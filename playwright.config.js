import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

import { defineConfig, devices } from '@playwright/test';

const root = dirname(fileURLToPath(import.meta.url));

const port = Number(process.env.SECONDSTAY_PORT || 8123);
// `localhost` et non `127.0.0.1` : une adresse IP n'est pas une « relying
// party » WebAuthn valide, les clés d'accès seraient refusées par le navigateur.
const host = process.env.SECONDSTAY_HOST || 'localhost';
const baseURL = process.env.SECONDSTAY_BASE_URL || `http://${host}:${port}`;
const collectsCoverage = Boolean(process.env.SECONDSTAY_COVERAGE_DIR);

// La campagne de scan dynamique sert l'application derrière un terminateur TLS
// muni d'un certificat généré pour la durée de la campagne, à qui rien ne fait
// confiance. L'exception est donc **conditionnée** à cette campagne, et se
// limite à la clé publique de ce certificat : une campagne ordinaire qui se
// mettrait à ignorer les erreurs de certificat cesserait de pouvoir en
// signaler une vraie.
const usesTls = process.env.SECONDSTAY_E2E_TLS === '1';

// Chaque requête traverse maintenant une poignée de main TLS, et bientôt un
// proxy. Les scénarios font le même travail et portent les mêmes assertions :
// seule la patience change. Sans cette mise à l'échelle, la latence du harnais
// serait rapportée comme des échecs de l'application.
const timeoutFactor = Number(process.env.SECONDSTAY_TIMEOUT_FACTOR || 1) || 1;

// Le scan dynamique rejoue la campagne **à travers** ZAP : c'est de là que
// vient toute la surface d'attaque. `--proxy-bypass-list=<-loopback>` est
// indispensable et discret : sans lui, Chromium contourne le proxy pour les
// adresses de boucle locale, ZAP n'enregistre rien, le scanner passif ne
// trouve aucun problème dans ce rien, et la campagne rend un certificat de
// bonne santé. `scripts/dast-support.php assert-sitemap` refuse ce silence.
const proxyServer = process.env.SECONDSTAY_E2E_PROXY || '';

/**
 * Empreinte de la clé publique du certificat de la campagne.
 *
 * Le certificat est produit ici, et non par la préparation globale, parce que
 * cette configuration est lue **avant** le lancement du navigateur : c'est le
 * seul endroit d'où l'empreinte peut atteindre ses arguments de démarrage. La
 * préparation globale réutilise ensuite le même fichier.
 */
function campaignCertificateSpki() {
    const support = resolve(root, 'scripts/dast-support.php');
    const certificate = process.env.SECONDSTAY_TLS_CERT
        || resolve(root, 'storage/temp', `tls-${host}-${port}.pem`);

    mkdirSync(dirname(certificate), { recursive: true });
    if (!existsSync(certificate)) {
        execFileSync('php', [support, 'generate-cert', certificate, host], { cwd: root, stdio: 'inherit' });
    }
    process.env.SECONDSTAY_TLS_CERT = certificate;

    // Le client HTTP de Playwright (`request`) ne vit pas dans le navigateur :
    // c'est du Node, et l'épinglage SPKI de Chromium ne le concerne pas. Sans
    // ancre de confiance il ne resterait que `ignoreHTTPSErrors`, qui
    // désarmerait la vérification pour **tout** le contexte, navigateur
    // compris — et rendrait l'épinglage décoratif au moment précis où l'on
    // scanne du TLS.
    //
    // On lui donne donc le certificat de la campagne comme ancre. Posé ici
    // parce que cette configuration est relue par chaque worker avant sa
    // première connexion, et que Node fige son magasin à la première
    // utilisation de TLS.
    // La campagne de scan fournit un paquet contenant **deux** ancres : le
    // certificat du terminateur et la racine que ZAP génère pour intercepter.
    // Hors scan, le certificat de campagne suffit.
    process.env.NODE_EXTRA_CA_CERTS = process.env.SECONDSTAY_CA_BUNDLE || certificate;

    return execFileSync('php', [support, 'cert-spki', certificate], { cwd: root, encoding: 'utf8' }).trim();
}

/**
 * Les clés publiques auxquelles le navigateur fait exception, et elles seules.
 *
 * Celle du terminateur de la campagne, et — quand un proxy d'interception est
 * en place — celle de l'autorité que ZAP génère à son démarrage, que
 * `scripts/dast.sh` calcule et transmet. Chromium accepte une liste séparée
 * par des virgules.
 */
function pinnedPublicKeys() {
    const keys = [campaignCertificateSpki()];
    const zapRoot = (process.env.SECONDSTAY_EXTRA_SPKI || '').trim();

    if (zapRoot !== '') {
        keys.push(zapRoot);
    }

    return keys.join(',');
}

// Le serveur de test est démarré et arrêté par `global-setup.js` /
// `global-teardown.js`, pas par l'option `webServer`. Deux raisons :
//
// 1. `dev-server.sh` détache le serveur puis rend la main ; Playwright, lui,
//    attend un processus qui reste au premier plan et considère sinon que le
//    serveur « s'est arrêté trop tôt ». C'est ce qui faisait échouer la
//    campagne en intégration continue alors qu'elle passait en local, où
//    `reuseExistingServer` masquait le problème ;
// 2. le serveur doit tourner avec les fournisseurs factices, et c'est la
//    préparation globale qui les connaît. Deux endroits qui démarrent le même
//    serveur avec deux environnements différents finissent toujours par
//    diverger.
/**
 * Quel binaire Chromium jouer.
 *
 * En temps normal, Playwright lance `chrome-headless-shell` : un binaire
 * allégé, plus rapide, et parfaitement suffisant pour la campagne E2E, qui est
 * verte avec lui.
 *
 * **Sous le proxy du scan dynamique, ce binaire meurt.** Quatre campagnes de
 * suite se sont terminées par `Received signal 11 SEGV_MAPERR 0000000001b0`,
 * toujours à la même adresse de faute, sur un test différent à chaque fois.
 * Une adresse constante n'est pas une pénurie de mémoire — c'est un
 * déréférencement déterministe. `--disable-dev-shm-usage` n'y a rien changé,
 * ce qui a fait tomber l'hypothèse de la mémoire partagée.
 *
 * Ce qui distingue ces campagnes des E2E vertes est le proxy, et le seul
 * élément qu'on puisse échanger sans toucher à ce qui est mesuré est le
 * binaire qui plante. `channel: 'chromium'` demande le navigateur complet,
 * dont le mode sans affichage est une implémentation différente — plus proche,
 * au passage, de ce qu'un visiteur exécute réellement.
 *
 * **Ce n'est pas une cause établie.** Docker ne peut pas démarrer dans
 * l'environnement de développement de ce dépôt, donc la campagne n'a pas pu
 * être reproduite ailleurs qu'en CI, et cette explication reste une hypothèse
 * — mieux ciblée que la précédente, pas démontrée pour autant. Si le plantage
 * survit à ce changement, c'est le binaire qu'il faut cesser de soupçonner.
 *
 * ## Les drapeaux vivent ici, et nulle part ailleurs
 *
 * `--disable-dev-shm-usage`, `--ignore-certificate-errors-spki-list` et
 * `--proxy-bypass-list` sont des options **de Chromium**. Posées dans le `use`
 * partagé, elles atteignaient aussi `mobile-safari`, et WebKit refuse de
 * démarrer sur une option qu'il ne connaît pas : « Cannot parse arguments:
 * Unknown option --disable-dev-shm-usage », les cinquante-trois scénarios
 * mobiles tombant sur le lancement du navigateur. La campagne du scan
 * dynamique ne l'avait jamais montré parce qu'elle ne joue qu'un projet
 * (`scripts/dast.sh`, `--project=desktop-chromium`).
 *
 * Chaque drapeau garde en revanche sa propre condition : `proxyServer` se
 * configure indépendamment de `usesTls`, et lier le contournement du proxy à
 * TLS aurait laissé un proxy en clair sans rien voir de la boucle locale —
 * une campagne qui mesure moins que ce qu'elle annonce.
 *
 * @returns {{ channel?: string, launchOptions: { args: string[], proxy?: { server: string } } }}
 */
function chromiumBinary() {
    return {
        ...(proxyServer !== '' ? { channel: 'chromium' } : {}),
        launchOptions: {
            // Sur Chromium, un proxy déclaré **par contexte** n'isole
            // réellement les contextes que si le navigateur a lui-même été
            // lancé avec un proxy. Sans cela, les contextes partagent l'état
            // réseau du navigateur, et le `proxy` du `use` partagé ne fait
            // qu'en donner l'illusion.
            //
            // Ce n'est pas une précaution théorique. Sous le proxy du scan
            // dynamique, une navigation faite juste après une connexion
            // repartait avec le pot à cookies d'un autre contexte : dans le
            // trace conservé par un travail en échec, `/fr/account` part avec
            // `secondstay_session=808c2587…; ss_locale=fr` et rend 200, puis
            // la requête suivante, cent douze millisecondes plus tard, part
            // avec `secondstay_session=303f6bfc…; ss_locale=nl` et rend 403.
            // Les **deux** cookies changent ensemble : ce n'est pas une
            // session régénérée, c'est un autre pot. L'application a répondu
            // correctement — une session inconnue sur une route authentifiée
            // doit rendre 403.
            //
            // `per-context` est la valeur que Playwright prévoit pour cela :
            // elle lance Chromium avec un proxy sans en imposer aucun, et
            // rend le proxy du contexte réellement propre à ce contexte. La
            // campagne ordinaire, sans proxy, ne voit pas cette ligne.
            ...(proxyServer !== '' ? { proxy: { server: 'per-context' } } : {}),
            args: [
                // Précaution standard pour Chromium en intégration continue,
                // où `/dev/shm` est petit. Elle ne dépend d'aucun harnais, et
                // elle n'est pas ce qui a corrigé le plantage décrit
                // ci-dessus : la campagne est morte de la même façon avec.
                '--disable-dev-shm-usage',

                // Le certificat épinglé n'existe que quand le harnais TLS
                // tourne.
                ...(usesTls ? [`--ignore-certificate-errors-spki-list=${pinnedPublicKeys()}`] : []),

                // Sans cela, Chromium adresse `localhost` en direct et le
                // proxy ne voit rien de la campagne — quel que soit le
                // protocole.
                ...(proxyServer !== '' ? ['--proxy-bypass-list=<-loopback>'] : [])
            ]
        }
    };
}

export default defineConfig({
    testDir: 'tests/e2e',
    globalSetup: './tests/e2e/global-setup.js',
    globalTeardown: './tests/e2e/global-teardown.js',
    // Une installation SecondStay = un logement = un état global partagé
    // (réglages, maintenance, sauvegardes). Les scénarios s'exécutent donc en
    // série : c'est la seule façon d'obtenir des résultats déterministes.
    fullyParallel: false,
    workers: 1,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    reporter: [
        ['list'],
        ['junit', { outputFile: 'build/reports/playwright-junit.xml' }],
        ['html', { outputFolder: 'playwright-report', open: 'never' }]
    ],
    outputDir: 'test-results',
    // Quand la couverture PHP est collectée, chaque requête est instrumentée
    // et le serveur répond nettement plus lentement. Les assertions sont les
    // mêmes : seule la patience change, faute de quoi un scénario juste
    // échouerait pour une raison qui ne dit rien du produit.
    timeout: (collectsCoverage ? 90000 : 30000) * timeoutFactor,
    expect: { timeout: (collectsCoverage ? 20000 : 7500) * timeoutFactor },
    use: {
        baseURL,
        // `ignoreHTTPSErrors` suffit à traverser l'avertissement, mais ne rend
        // pas l'origine **sûre** aux yeux de Chromium : un service worker
        // refuse de s'enregistrer sur une origine dont le certificat n'est pas
        // valide, et le scénario PWA resterait bloqué jusqu'à son délai — un
        // défaut du harnais rapporté comme un défaut du produit.
        //
        // Les deux drapeaux plus simples ne conviennent pas :
        // `--allow-insecure-localhost` ne confère pas le statut d'origine sûre,
        // et `--ignore-certificate-errors` le confère mais fait accepter
        // n'importe quel certificat — au prix, constaté ici, d'un navigateur
        // qui se ferme ou se bloque à la création d'un contexte.
        //
        // On épingle donc la clé publique de la campagne : le navigateur ne
        // fait d'exception que pour ce certificat-là, généré à l'instant et
        // vivant le temps d'une campagne. Conditionné, comme le reste — une
        // campagne ordinaire doit rester capable de signaler un vrai défaut de
        // certificat.
        //
        // Et **seulement** l'épinglage, tant qu'aucun proxy ne s'interpose :
        // `ignoreHTTPSErrors` accompagnait cette ligne et annulait ce qu'elle
        // prétend faire, en désactivant la validation pour tout le contexte.
        // Un autre certificat invalide passait alors aussi, ce qui rendait
        // l'épinglage décoratif — juste au moment où l'on scanne le TLS.
        //
        // La campagne de scan interpose ZAP, qui **est un intercepteur par
        // construction** : le pair TLS du navigateur n'y est plus le
        // terminateur mais ZAP, qui ré-signe chaque connexion avec une
        // autorité générée à son démarrage. La réponse n'est pas de relâcher
        // la vérification pour autant — `ignoreHTTPSErrors` traverse
        // l'avertissement sans rendre l'origine **sûre**, et deux scénarios de
        // service worker tombent alors, sans rapport avec le produit. C'est
        // constaté, pas supposé.
        //
        // `scripts/dast.sh` récupère donc la racine de ZAP et la passe ici :
        // le navigateur fait exception pour ces deux clés, et pour aucune
        // autre.
        ...(proxyServer !== '' ? { proxy: { server: proxyServer } } : {}),
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris'
    },
    projects: [
        {
            // L'installation est jouée une seule fois, dans un navigateur réel,
            // et produit l'état de session administrateur réutilisé ensuite.
            name: 'install',
            testMatch: /install\.setup\.js$/,
            use: { ...devices['Desktop Chrome'], ...chromiumBinary() }
        },
        {
            name: 'desktop-chromium',
            testIgnore: /install\.setup\.js$/,
            use: { ...devices['Desktop Chrome'], ...chromiumBinary() },
            dependencies: ['install']
        },
        {
            name: 'mobile-safari',
            testIgnore: /install\.setup\.js$/,
            use: { ...devices['iPhone 14'] },
            dependencies: ['install']
        }
    ]
});

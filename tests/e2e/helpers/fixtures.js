import { writeFile } from 'node:fs/promises';

import { expect, test } from '@playwright/test';

/**
 * En cas d'échec, l'état des pots à cookies de tous les contextes vivants.
 *
 * POURQUOI CETTE INSTRUMENTATION EXISTE
 * ---------------------------------------------------------------------------
 * La campagne du scan dynamique tombe environ trois fois sur quatre, sur des
 * scénarios différents et toujours de la même façon : après une
 * authentification réussie, une requête ultérieure est traitée comme anonyme,
 * l'application répond correctement 403 — ou renvoie vers la page de connexion
 * — et le scénario meurt sur un élément que cette page ne porte pas.
 *
 * Le trace de Playwright montre les en-têtes **envoyés**. Il a établi que la
 * requête fautive part avec un jeu de cookies complet et cohérent qui n'est pas
 * celui du test : `secondstay_session` **et** `ss_locale` changent ensemble,
 * d'une requête à la suivante, à cent douze millisecondes d'intervalle.
 *
 * Ce que le trace ne montre pas, c'est ce que le contexte **contenait** à cet
 * instant. Or c'est la seule mesure qui sépare les deux explications
 * restantes, et elles mènent dans des directions opposées :
 *
 *   - le contexte porte le bon cookie, et quelque chose le remplace en chemin
 *     — l'enquête va alors vers le proxy ;
 *   - le contexte porte lui-même le mauvais cookie — elle va vers Playwright.
 *
 * Chaque mesure coûte une demi-heure d'intégration continue. Ajouter une
 * hypothèse de plus reviendrait à tirer à pile ou face à ce prix-là.
 *
 * `browser` est demandé plutôt que `page` : il est de portée « worker » et
 * déjà instancié, là où demander `page` créerait un contexte pour les
 * scénarios qui n'en utilisent pas — l'instrumentation changerait alors ce
 * qu'elle mesure. `browser.contexts()` rend tous les contextes vivants, y
 * compris ceux que les scénarios créent eux-mêmes par `anonymousContext()`.
 */
test.afterEach(async ({ browser }, testInfo) => {
    if (testInfo.status === testInfo.expectedStatus) {
        return;
    }

    const contexts = [];
    for (const [index, context] of browser.contexts().entries()) {
        try {
            contexts.push({
                index,
                pages: context.pages().map((page) => page.url()),
                cookies: await context.cookies(),
            });
        } catch (error) {
            // Un contexte fermé pendant le démontage n'est pas une raison de
            // faire échouer le démontage lui-même : on note l'impossibilité.
            contexts.push({ index, error: String(error) });
        }
    }

    // Écrit sur disque plutôt que joint par `body` : un attachement à corps
    // vit dans le rapport, quand l'artefact que la CI conserve en cas d'échec
    // est l'arborescence `test-results/`. Une preuve qui ne sort pas de la
    // machine où elle a été produite n'en est pas une.
    const target = testInfo.outputPath('pots-a-cookies.json');
    await writeFile(
        target,
        JSON.stringify({ capturedAt: new Date().toISOString(), contexts }, null, 2),
        'utf8'
    );
    await testInfo.attach('pots-a-cookies.json', { path: target, contentType: 'application/json' });
});

/**
 * Données de test partagées entre les scénarios E2E.
 * Aucune donnée réelle du logement ne figure dans le dépôt.
 */
export const ADMIN = {
    email: 'owner@example.test',
    password: 'Marée-Haute-2026!',
    firstName: 'Claire',
    lastName: 'Dubois',
    phone: '+33600000000'
};

export const PROPERTY_NAME = 'Maison des Pins';

export const ADMIN_STATE_FILE = 'tests/e2e/.auth/admin.json';

export async function signIn(page, email = ADMIN.email, password = ADMIN.password, locale = 'fr') {
    await page.goto(`/${locale}/login`);

    // Le champ e-mail porte `autofocus`. Sous WebKit ce focus peut arriver
    // **après** que la saisie a commencé : `fill('#password')` place le focus
    // sur le mot de passe, l'autofocus le ramène sur l'e-mail, et le texte
    // s'insère alors au début du champ e-mail. La connexion échoue ensuite sur
    // « adresse e-mail ou mot de passe incorrect », ce qui ne dit rien du
    // produit — c'est le harnais qui a tapé au mauvais endroit.
    //
    // On attend donc que l'autofocus ait eu lieu avant de saisir quoi que ce
    // soit. Attendre le chargement de la page ne suffit pas : c'est
    // précisément ce qui était fait, et l'échec survenait quand même.
    await expect(page.locator('#email')).toBeFocused();

    await page.fill('#email', email);
    await page.fill('#password', password);
    await page.click('form[data-testid="login-form"] button[type="submit"]');
}

/**
 * Connexion réellement établie avant de continuer.
 *
 * `click()` rend la main dès que le clic est délivré, pas quand la navigation
 * est terminée : sous WebKit, partir aussitôt vers une page protégée y produit
 * un 403 fantôme, parce que le cookie de session n'est pas encore posé. On
 * attend donc l'espace client, qui est la destination du voyageur.
 *
 * La redirection utilise la langue **du compte**, qui n'est pas forcément
 * celle de la page de connexion.
 */
export async function signInAndWait(page, email, password, locale = 'fr') {
    await signIn(page, email, password, locale);
    await page.waitForURL(/\/(fr|en|nl|de)\/account$/);
}

/**
 * Contexte réellement anonyme.
 *
 * `browser.newContext()` hérite du `storageState` déclaré par `test.use` :
 * il faut donc vider explicitement l'état pour simuler un visiteur.
 */
export async function anonymousContext(browser) {
    return browser.newContext({ storageState: { cookies: [], origins: [] } });
}

/**
 * Remet à zéro les compteurs de limitation de débit via l'action
 * d'administration prévue pour cela.
 *
 * Les deux projets Playwright partagent la même installation et la même
 * adresse IP : sans cela, les scénarios d'inscription se bloqueraient
 * mutuellement. On utilise la fonctionnalité réelle du produit, jamais une
 * porte dérobée réservée aux tests.
 */
export async function clearRateLimits(browser) {
    const context = await browser.newContext({ storageState: ADMIN_STATE_FILE });
    const page = await context.newPage();

    await page.goto('/fr/admin/diagnostics');
    await page.click('[data-testid="clear-rate-limits"]');
    await page.waitForSelector('[data-flash-type="success"]');

    await context.close();
}

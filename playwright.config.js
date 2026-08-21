import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.SECONDSTAY_PORT || 8123);
// `localhost` et non `127.0.0.1` : une adresse IP n'est pas une « relying
// party » WebAuthn valide, les clés d'accès seraient refusées par le navigateur.
const host = process.env.SECONDSTAY_HOST || 'localhost';
const baseURL = process.env.SECONDSTAY_BASE_URL || `http://${host}:${port}`;
const collectsCoverage = Boolean(process.env.SECONDSTAY_COVERAGE_DIR);

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
    timeout: collectsCoverage ? 90000 : 30000,
    expect: { timeout: collectsCoverage ? 20000 : 7500 },
    use: {
        baseURL,
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
            use: { ...devices['Desktop Chrome'] }
        },
        {
            name: 'desktop-chromium',
            testIgnore: /install\.setup\.js$/,
            use: { ...devices['Desktop Chrome'] },
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

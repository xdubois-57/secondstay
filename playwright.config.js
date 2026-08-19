import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.SECONDSTAY_PORT || 8123);
const host = process.env.SECONDSTAY_HOST || '127.0.0.1';
const baseURL = process.env.SECONDSTAY_BASE_URL || `http://${host}:${port}`;

export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: true,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    workers: process.env.CI ? 2 : undefined,
    reporter: [
        ['list'],
        ['junit', { outputFile: 'build/reports/playwright-junit.xml' }],
        ['html', { outputFolder: 'playwright-report', open: 'never' }]
    ],
    outputDir: 'test-results',
    timeout: 30000,
    expect: { timeout: 7500 },
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
            name: 'desktop-chromium',
            use: { ...devices['Desktop Chrome'] }
        },
        {
            name: 'mobile-safari',
            use: { ...devices['iPhone 14'] }
        }
    ],
    webServer: {
        command: './scripts/dev-server.sh start',
        url: `${baseURL}/api/health`,
        reuseExistingServer: !process.env.CI,
        timeout: 60000
    }
});

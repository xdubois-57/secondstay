import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        include: ['tests/js/**/*.test.js'],
        environment: 'node',
        reporters: ['default', 'junit'],
        outputFile: {
            junit: 'build/reports/vitest-junit.xml'
        },
        coverage: {
            provider: 'v8',
            reporter: ['text-summary', 'lcov'],
            reportsDirectory: 'coverage',
            include: ['public/assets/js/modules/**/*.js']
        }
    }
});

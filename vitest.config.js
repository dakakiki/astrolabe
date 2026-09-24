import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

// Separate from vite.config.js: the Laravel Vite plugin refuses to start in CI,
// and the unit tests need only the path alias.
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['tests/js/**/*.test.js'],
    },
});

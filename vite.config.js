import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// No `server` block: this intentionally keeps Vite's default dev-server
// host (`false`, i.e. localhost-only, not 0.0.0.0/LAN). Reviewed as part of
// docs/security-advisories.md advisory 1 (esbuild/Vite dev-server GHSAs) —
// do not add a `host`/`cors` override here without re-reading that section.
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/filament.scss',
                'resources/js/filament.js'
            ],
            refresh: true,
        }),
    ],
});

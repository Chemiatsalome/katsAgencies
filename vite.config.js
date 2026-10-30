import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            // Explicit, extension-qualified globs instead of `refresh: true` —
            // the default `resources/views/**` glob was also matching
            // storage/framework/views/** (Blade's compiled cache, rewritten
            // on every render), causing a full page reload on almost every
            // request.
            refresh: [
                'resources/views/**/*.blade.php',
                'resources/lang/**',
                'lang/**',
                'app/Livewire/**/*.php',
                'app/View/Components/**/*.php',
                'routes/**/*.php',
            ],
        }),
    ],
    server: {
        watch: {
            // Vite's own base watcher (independent of the plugin's `refresh`
            // globs above) watches the whole project root and reloads on
            // ANY new/changed file it isn't told to ignore — including
            // Laravel's own runtime-written files (compiled Blade views,
            // cached config/routes, logs), which are rewritten on nearly
            // every request and were causing a reload loop even after the
            // `refresh` globs were narrowed.
            ignored: [
                '**/storage/**',
                '**/bootstrap/cache/**',
                '**/vendor/**',
                '**/node_modules/**',
            ],
        },
    },
});

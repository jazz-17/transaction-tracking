import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        // Wayfinder regenerates the TS route helpers by shelling out to
        // `php artisan wayfinder:generate`. Skip it when VITE_SKIP_WAYFINDER is set:
        // the Docker node-build stage has no PHP, and the helpers are already
        // generated in the `wayfinder` stage and copied in.
        ...(process.env.VITE_SKIP_WAYFINDER
            ? []
            : [wayfinder({ formVariants: true })]),
    ],
});

import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    esbuild: { jsx: "automatic" },
    plugins: [
        laravel({
            input: [
                "resources/css/app.css",
                "resources/css/filament/theme.css",
                "resources/js/app.js",
                "resources/checkout/main.tsx",
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ["**/storage/framework/views/**"],
        },
    },
});

import { resolve } from "path";
import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import i18nExtractKeys from "./i18nExtractKeys.vite.js";

// https://vitejs.dev/config/
export default defineConfig({
    target: "es2016",
    plugins: [i18nExtractKeys(), vue()],
    build: {
        lib: {
            // Could also be a dictionary or array of multiple entry points
            entry: resolve(__dirname, "resources/js/main.js"),
            name: "ExamplePlugin",
            // the proper extensions will be added
            fileName: "build",
            // important to generate Immediately Invoked Function Expression as output
            // this can be easily loaded via script tag and ensure it will be executed immediatelly
            // otherwise there would be risk that page will be rendered before plugin components gets registered
            formats: ["iife"],
        },
        outDir: resolve(__dirname, "public/build"),
        rollupOptions: {
            // make sure to externalize deps that shouldn't be bundled
            // into your library
            external: ["vue"],
            output: {
                // Provide global variables to use in the UMD build
                // for externalized deps
                globals: {
                    vue: "pkp.modules.vue",
                },
            },
        },
    },
});

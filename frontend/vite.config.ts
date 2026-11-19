// Builds the web pages as a single-page app. Laravel serves the prerendered
// shell for every page and answers everything under /api/.
import path from "node:path";
import tailwindcss from "@tailwindcss/vite";
import { tanstackStart } from "@tanstack/react-start/plugin/vite";
import viteReact from "@vitejs/plugin-react";
import { defineConfig } from "vite";
import tsConfigPaths from "vite-tsconfig-paths";

export default defineConfig({
  css: { transformer: "lightningcss" },
  server: {
    port: 8080,
    // `php artisan serve` answers the API during development.
    proxy: { "/api": "http://127.0.0.1:8000" },
  },
  plugins: [
    tailwindcss(),
    tsConfigPaths({ projects: ["./tsconfig.json"] }),
    tanstackStart({ spa: { enabled: true, maskPath: "/app-shell" } }),
    viteReact(),
  ],
  resolve: {
    alias: { "@": path.resolve(import.meta.dirname, "src") },
    dedupe: [
      "react",
      "react-dom",
      "react/jsx-runtime",
      "react/jsx-dev-runtime",
      "@tanstack/react-query",
      "@tanstack/query-core",
    ],
  },
});

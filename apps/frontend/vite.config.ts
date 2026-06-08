import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: "dist",
    emptyOutDir: true,
    sourcemap: true
  },
  server: {
    port: 5173,
    proxy: {
      "/api": "http://localhost:8080",
      "/health": "http://localhost:8080",
      "/ready": "http://localhost:8080"
    }
  }
});

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
      // La :8080 ora redirige su HTTPS: si punta direttamente all'entrypoint
      // TLS di Traefik (secure: false per il certificato self-signed locale)
      "/api": { target: "https://localhost:8443", secure: false },
      "/health": { target: "https://localhost:8443", secure: false },
      "/ready": { target: "https://localhost:8443", secure: false }
    }
  }
});

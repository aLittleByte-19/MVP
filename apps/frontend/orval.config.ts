import { defineConfig } from "orval";

export default defineConfig({
  mvpApi: {
    input: "../../openapi/v1/alittlebyte-mvp-api.yaml",
    output: {
      mode: "single",
      client: "angular",
      target: "src/api/generated/mvp-api.ts",
      schemas: "src/api/generated/model",
      clean: true
    }
  }
});

import { defineConfig } from "orval";

export default defineConfig({
  pocApi: {
    input: "../../openapi/v1/alittlebyte-poc-api.yaml",
    output: {
      mode: "single",
      client: "angular",
      target: "src/api/generated/poc-api.ts",
      schemas: "src/api/generated/model",
      clean: true
    }
  }
});

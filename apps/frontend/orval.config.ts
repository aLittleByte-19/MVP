import { defineConfig } from "orval";

export default defineConfig({
  pocApi: {
    input: "../../openapi/poc-api.v1.yaml",
    output: {
      mode: "single",
      client: "fetch",
      target: "src/api/generated/poc-api.ts",
      schemas: "src/api/generated/model",
      clean: true,
      override: {
        requestOptions: true
      }
    }
  }
});

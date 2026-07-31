import { ErrorHandler } from "@angular/core";
import { appConfig } from "./app.config";
import { GlobalErrorHandler } from "./core/observability/global-error-handler";

describe("appConfig", () => {
  it("registra routing, HTTP e il gestore globale degli errori", () => {
    expect(appConfig.providers).toHaveLength(4);
    expect(appConfig.providers).toContainEqual({ provide: ErrorHandler, useClass: GlobalErrorHandler });
  });
});

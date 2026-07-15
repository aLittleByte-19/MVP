import { bootstrapApplication } from "@angular/platform-browser";
import { AppComponent } from "./app/app";
import { appConfig } from "./app/app.config";

bootstrapApplication(AppComponent, appConfig).catch((error: unknown) => {
  // Ultimo livello di difesa: un fallimento di bootstrap non ha ancora un
  // ErrorHandler Angular disponibile, quindi si registra direttamente.
  console.error("[mvp] bootstrap failed", error);
});

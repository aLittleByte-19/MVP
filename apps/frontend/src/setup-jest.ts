import { setupZoneTestEnv } from "jest-preset-angular/setup-env/zone";

// Il modulo esporta la funzione senza invocarla: senza questa chiamata
// TestBed resta senza ambiente e ogni spec che lo usa fallisce.
setupZoneTestEnv();

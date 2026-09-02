/** @type {import('jest').Config} */
const { frontend: coverageThresholds } = require("../../coverage-thresholds.json");

module.exports = {
  preset: "jest-preset-angular",
  setupFilesAfterEnv: ["<rootDir>/src/setup-jest.ts"],
  testEnvironment: "jest-preset-angular/environments/jest-jsdom-env",
  testMatch: ["<rootDir>/src/**/*.spec.ts"],
  // Senza questo elenco Jest misura la copertura solo sui file che i test
  // importano: i componenti mai testati sparirebbero dal denominatore e la
  // percentuale risulterebbe molto piu' alta di quella reale.
  collectCoverageFrom: [
    "src/app/**/*.ts",
    "!src/app/**/*.spec.ts",
    // Client generato da Orval a partire dal contratto OpenAPI: non e' codice
    // scritto qui e la CI verifica gia' che non vada in drift dal contratto.
    "!src/api/generated/**",
    // Bootstrap e configurazione: nessuna logica da verificare.
    "!src/main.ts",
    "!src/environments/**"
  ],
  coverageReporters: ["text", "json-summary", ["lcov", { projectRoot: "../.." }]],
  // Jest applica i minimi rigidi definiti nella fonte condivisa. Lo script
  // scripts/ci/check-coverage-thresholds.mjs verifica gli stessi valori sui
  // report aggregati prodotti dalla CI.
  coverageThreshold: {
    global: {
      statements: coverageThresholds.statements,
      functions: coverageThresholds.functions,
      branches: coverageThresholds.branches
    }
  },
  // Jest apre un worker per CPU: con otto core dentro il container la memoria
  // finisce a meta' suite e il kernel ne uccide qualcuno con SIGKILL, che si
  // presenta come "Test suite failed to run" senza un solo test fallito.
  // Quattro worker bastano a tenere il tempo di esecuzione, e il limite di
  // memoria per worker li fa riciclare prima che il consumo si accumuli.
  maxWorkers: 4,
  workerIdleMemoryLimit: "768MB",
  moduleNameMapper: {
    "^src/(.*)$": "<rootDir>/src/$1"
  },
  transform: {
    "^.+\\.(ts|mjs|js|html)$": [
      "jest-preset-angular",
      {
        tsconfig: "<rootDir>/tsconfig.spec.json",
        stringifyContentPathRegex: "\\.(html|svg)$"
      }
    ]
  }
};

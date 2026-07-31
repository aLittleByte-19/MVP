/** @type {import('jest').Config} */
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

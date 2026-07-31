import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const [scope, reportPath] = process.argv.slice(2);

if (!scope || !reportPath || !["backend", "frontend"].includes(scope)) {
  console.error("Uso: node scripts/ci/check-coverage-thresholds.mjs <backend|frontend> <report>");
  process.exit(2);
}

const root = process.cwd();
const thresholds = JSON.parse(readFileSync(resolve(root, "coverage-thresholds.json"), "utf8"));
const configured = thresholds[scope];
const actual = scope === "frontend" ? readFrontendReport(reportPath) : readBackendReport(reportPath);
let failed = false;

for (const [metric, minimum] of Object.entries(configured)) {
  const value = actual[metric];
  if (!Number.isFinite(value)) {
    console.error(`Coverage ${scope}/${metric}: metrica assente dal report.`);
    failed = true;
    continue;
  }

  if (value < minimum) {
    console.error(`Coverage ${scope}/${metric}: ${value.toFixed(2)}%, sotto il minimo rigido ${minimum}%.`);
    failed = true;
  } else {
    console.log(`Coverage ${scope}/${metric}: ${value.toFixed(2)}%, minimo rigido ${minimum}% rispettato.`);
  }
}

process.exitCode = failed ? 1 : 0;

function readFrontendReport(path) {
  const report = JSON.parse(readFileSync(resolve(root, path), "utf8"));
  return {
    statements: report.total.statements.pct,
    functions: report.total.functions.pct,
    branches: report.total.branches.pct
  };
}

function readBackendReport(path) {
  const report = readFileSync(resolve(root, path), "utf8");
  const coverage = report.match(/<coverage\s+([^>]+)>/);

  if (!coverage) {
    throw new Error(`Elemento <coverage> non trovato in ${path}`);
  }

  const attributes = Object.fromEntries(
    [...coverage[1].matchAll(/([a-z-]+)="([^"]*)"/g)].map((match) => [match[1], Number(match[2])])
  );

  // I branch sono valorizzati solo con la path coverage attiva: senza, il totale
  // resta 0, la percentuale diventa NaN e il controllo fallisce invece di passare
  // su una metrica non misurata.
  return {
    lines: percentage(attributes["lines-covered"], attributes["lines-valid"]),
    branches: percentage(attributes["branches-covered"], attributes["branches-valid"])
  };
}

function percentage(covered, total) {
  return total > 0 ? (covered / total) * 100 : Number.NaN;
}

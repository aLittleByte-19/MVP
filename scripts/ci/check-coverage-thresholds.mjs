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
  const metrics = [...report.matchAll(/<metrics\s+([^>]+)\/>/g)];
  const project = metrics[metrics.length - 1];

  if (!project) {
    throw new Error(`Metriche Clover non trovate in ${path}`);
  }

  const attributes = Object.fromEntries(
    [...project[1].matchAll(/([a-z]+)="([0-9]+)"/g)].map((match) => [match[1], Number(match[2])])
  );

  return {
    lines: percentage(attributes.coveredstatements, attributes.statements),
    branches: percentage(attributes.coveredconditionals, attributes.conditionals)
  };
}

function percentage(covered, total) {
  return total > 0 ? (covered / total) * 100 : Number.NaN;
}

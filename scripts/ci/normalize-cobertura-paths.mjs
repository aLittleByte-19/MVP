// PHPUnit scrive nel report Cobertura i filename relativi alla radice comune dei
// file coperti (qui `app`, dall'<include> di phpunit.xml) e mette quella radice,
// col path assoluto del container, nell'elemento <source>.
//
// diff-cover indicizza ogni classe sia per `filename` sia per join(<source>, filename):
// con <source>/var/www/html/app nessuna delle due chiavi corrisponde ai path
// prodotti da `git diff`, quindi nessun file modificato risulta misurato e il
// gate passerebbe silenziosamente su zero righe. Riscrivendo <source> come path
// relativo alla radice della repo la chiave diventa `app/...` e combacia.
//
// Se la radice comune coincide col working directory del container, <source>
// diventa `.` e l'aggancio avviene comunque tramite il solo `filename`, che in
// quel caso contiene gia' il prefisso `app/`.

import { readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";

const [reportPath, containerRoot] = process.argv.slice(2);

if (!reportPath || !containerRoot) {
  console.error("Uso: node scripts/ci/normalize-cobertura-paths.mjs <report> <root-container>");
  process.exit(2);
}

const file = resolve(process.cwd(), reportPath);
const report = readFileSync(file, "utf8");
const root = containerRoot.replace(/\/+$/, "");
const prefix = `${root}/`;
const sources = [...report.matchAll(/<source>([^<]*)<\/source>/g)].map((match) => match[1].trim());
let failed = false;

if (sources.length === 0) {
  console.error(`Elemento <source> assente in ${reportPath}: il report non ha il formato Cobertura atteso.`);
  failed = true;
}

if (!/<class\s[^>]*filename="/.test(report)) {
  console.error(`Nessuna classe con attributo filename in ${reportPath}: report vuoto o inatteso.`);
  failed = true;
}

const outside = sources.filter((source) => source !== root && !source.startsWith(prefix));

if (outside.length > 0) {
  console.error(
    `Sorgenti esterne a ${root} in ${reportPath}: ${outside.join(", ")}. ` +
      "Senza normalizzazione diff-cover non aggancerebbe alcun file."
  );
  failed = true;
}

if (failed) {
  process.exit(1);
}

writeFileSync(file, report.replace(/<source>([^<]*)<\/source>/g, (_match, value) => `<source>${toRepoPath(value.trim())}</source>`));

for (const source of sources) {
  console.log(`Sorgente Cobertura normalizzata: ${source} -> ${toRepoPath(source)}`);
}

function toRepoPath(source) {
  return source === root ? "." : source.slice(prefix.length);
}

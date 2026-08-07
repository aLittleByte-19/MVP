// Gate di sicurezza sulle dipendenze PHP di produzione, controparte dell'audit
// npm del job frontend. Legge da stdin l'output di:
//   composer audit --locked --no-dev --abandoned=report --format=json
//
// Il filtro non e' per severita' stretta come su npm: il database PHP
// (FriendsOfPHP/security-advisories) lascia spesso `severity` a null, come nel
// caso di CVE-2026-54133, che GitHub classifica critical. Un advisory senza
// severita' dichiarata viene quindi trattato come bloccante: passano solo
// quelli esplicitamente low o moderate.
const TOLLERATE = new Set(["low", "medium", "moderate"]);

async function leggiStdin() {
  let contenuto = "";

  process.stdin.setEncoding("utf8");

  for await (const blocco of process.stdin) {
    contenuto += blocco;
  }

  return contenuto;
}

const input = (await leggiStdin()).trim();

if (!input) {
  console.error("Nessun output da composer audit: gate non verificabile.");
  process.exit(2);
}

let report;

try {
  report = JSON.parse(input);
} catch {
  console.error("Output di composer audit non interpretabile come JSON.");
  process.exit(2);
}

// Con zero advisory composer serializza un array vuoto invece di un oggetto.
const advisories = Array.isArray(report.advisories) ? {} : (report.advisories ?? {});
const bloccanti = [];
let tollerati = 0;

for (const entries of Object.values(advisories)) {
  for (const advisory of entries) {
    const severita = advisory.severity?.toLowerCase() ?? null;

    if (severita !== null && TOLLERATE.has(severita)) {
      tollerati++;
      console.log(
        `Tollerato ${advisory.packageName}: ${advisory.cve ?? advisory.advisoryId} (${severita}).`,
      );
      continue;
    }

    bloccanti.push({ ...advisory, severita });
  }
}

for (const advisory of bloccanti) {
  console.error(
    `BLOCCANTE ${advisory.packageName} ${advisory.affectedVersions}: `
      + `${advisory.cve ?? advisory.advisoryId} (${advisory.severita ?? "severita' non dichiarata"})`
      + `\n  ${advisory.title}\n  ${advisory.link}`,
  );
}

for (const pacchetto of report.abandoned ?? []) {
  console.log(`Pacchetto abbandonato: ${pacchetto.packageName ?? pacchetto}. Non blocca il gate.`);
}

if (bloccanti.length === 0) {
  console.log(`Nessun advisory bloccante sulle dipendenze di produzione (${tollerati} tollerati).`);
  process.exitCode = 0;
} else {
  console.error(
    `\n${bloccanti.length} advisory bloccanti. Correggere la dipendenza oppure, se non applicabile, `
      + "dichiararlo in composer.json con config.audit.ignore motivando la scelta.",
  );
  process.exitCode = 1;
}

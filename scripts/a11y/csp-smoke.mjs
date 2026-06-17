// Verifica che la SPA si carichi e funzioni con la CSP enforced: a differenza
// dell'audit axe (che usa bypassCSP per poter iniettare il proprio script),
// questo contesto applica la policy reale e fallisce su qualsiasi violazione
// CSP o errore di pagina.
import { chromium } from 'playwright';

const urls = process.argv.slice(2);

if (urls.length === 0) {
  console.error('Usage: node scripts/a11y/csp-smoke.mjs <url> [url...]');
  process.exit(1);
}

const browser = await chromium.launch({
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

let failures = 0;

try {
  for (const url of urls) {
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const problems = [];

    page.on('console', (message) => {
      if (message.type() === 'error' || /Content[- ]Security[- ]Policy|Refused to/i.test(message.text())) {
        problems.push(`console ${message.type()}: ${message.text()}`);
      }
    });
    page.on('pageerror', (error) => {
      problems.push(`pageerror: ${error.message}`);
    });

    const response = await page.goto(url, { waitUntil: 'networkidle' });

    if (!response || !response.ok()) {
      problems.push(`HTTP ${response ? response.status() : 'senza risposta'} su ${url}`);
    }

    const csp = response?.headers()['content-security-policy'] ?? '';

    if (!csp.includes("default-src 'self'")) {
      problems.push(`header Content-Security-Policy assente o inatteso: "${csp}"`);
    }

    const rendered = await page
      .waitForSelector('main', { timeout: 15000 })
      .then(() => true)
      .catch(() => false);

    if (!rendered) {
      problems.push('la SPA non ha renderizzato <main> entro 15s');
    }

    if (problems.length === 0) {
      console.log(`${url}: SPA funzionante con CSP enforced`);
    } else {
      failures += 1;
      console.error(`${url}: ${problems.length} problemi con CSP enforced`);

      for (const problem of problems) {
        console.error(`  - ${problem}`);
      }
    }

    await context.close();
  }
} finally {
  await browser.close();
}

process.exit(failures > 0 ? 1 : 0);

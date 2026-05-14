import axe from 'axe-core';
import { chromium } from 'playwright';

const urls = process.argv.slice(2);

if (urls.length === 0) {
  console.error('Usage: node scripts/a11y/axe-playwright.mjs <url> [url...]');
  process.exit(1);
}

const browser = await chromium.launch({
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

let violationsCount = 0;

try {
  for (const url of urls) {
    const page = await browser.newPage();
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.addScriptTag({ content: axe.source });

    const result = await page.evaluate(async () => {
      return await window.axe.run(document);
    });

    if (result.violations.length === 0) {
      console.log(`${url}: 0 violations found`);
    } else {
      console.error(`${url}: ${result.violations.length} violations found`);

      for (const violation of result.violations) {
        console.error(`- ${violation.id}: ${violation.help}`);
        for (const node of violation.nodes) {
          console.error(`  ${node.target.join(', ')}`);
        }
      }

      violationsCount += result.violations.length;
    }

    await page.close();
  }
} finally {
  await browser.close();
}

process.exitCode = violationsCount > 0 ? 1 : 0;

import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const baseUrl = process.argv[2];

if (!baseUrl) {
  console.error('Usage: node scripts/a11y/visual-audit.mjs <base-url>');
  process.exit(1);
}

const outputDir = 'reports/ui-audit';
const viewports = [
  { name: 'mobile', width: 375, height: 812 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 },
];
const views = [
  { id: 'overview', navLabel: 'Overview' },
  { id: 'assistant', navLabel: 'Assistant' },
  { id: 'copilot', navLabel: 'Co-Pilot' },
];
const themes = ['light', 'dark'];

await mkdir(outputDir, { recursive: true });

const browser = await chromium.launch({
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

let issuesCount = 0;

function reportIssues(label, issues) {
  if (issues.length === 0) {
    console.log(`${label}: OK`);
    return;
  }

  console.error(`${label}: ${issues.length} possibili problemi`);

  for (const issue of issues) {
    console.error(`  - ${issue}`);
  }

  issuesCount += issues.length;
}

async function collectLayoutIssues(page) {
  return page.evaluate(() => {
    const issues = [];
    const viewportWidth = window.innerWidth;

    const describe = (el) => {
      const id = el.id ? `#${el.id}` : '';
      const cls = el.classList.length ? `.${[...el.classList].slice(0, 2).join('.')}` : '';
      return `${el.tagName.toLowerCase()}${id}${cls}`;
    };

    if (document.documentElement.scrollWidth > viewportWidth + 1) {
      issues.push(`overflow orizzontale di pagina: scrollWidth ${document.documentElement.scrollWidth}px > viewport ${viewportWidth}px`);
    }

    const elements = [...document.querySelectorAll('body *')].filter((el) => {
      if (el.namespaceURI === 'http://www.w3.org/2000/svg' && el.tagName.toLowerCase() !== 'svg') {
        return false;
      }

      const style = getComputedStyle(el);
      return style.display !== 'none' && style.visibility !== 'hidden' && el.getClientRects().length > 0;
    });

    const insideScrollContainer = (el) => {
      for (let node = el.parentElement; node && node !== document.body; node = node.parentElement) {
        const overflowX = getComputedStyle(node).overflowX;

        if (overflowX === 'auto' || overflowX === 'scroll') {
          return true;
        }
      }

      return false;
    };

    for (const el of elements) {
      const rect = el.getBoundingClientRect();

      if (rect.width > 1 && (rect.right > viewportWidth + 8 || rect.left < -8) && !insideScrollContainer(el)) {
        issues.push(`fuori viewport: ${describe(el)} (left ${Math.round(rect.left)}, right ${Math.round(rect.right)}, viewport ${viewportWidth})`);
      }
    }

    const skipPositions = new Set(['absolute', 'fixed', 'sticky']);

    for (const el of elements) {
      if (el.tagName.toLowerCase() === 'svg') {
        continue;
      }

      const children = [...el.children].filter((child) => {
        const style = getComputedStyle(child);
        return style.display !== 'none' && !skipPositions.has(style.position) && child.getClientRects().length > 0;
      });

      for (let i = 0; i < children.length; i += 1) {
        for (let j = i + 1; j < children.length; j += 1) {
          const a = children[i].getBoundingClientRect();
          const b = children[j].getBoundingClientRect();

          if (a.width < 4 || a.height < 4 || b.width < 4 || b.height < 4) {
            continue;
          }

          const overlapX = Math.min(a.right, b.right) - Math.max(a.left, b.left);
          const overlapY = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);

          if (overlapX > 4 && overlapY > 4) {
            issues.push(`overlap tra fratelli: ${describe(children[i])} e ${describe(children[j])} (${Math.round(overlapX)}x${Math.round(overlapY)}px)`);
          }
        }
      }
    }

    return [...new Set(issues)].slice(0, 25);
  });
}

try {
  for (const viewport of viewports) {
    const context = await browser.newContext({
      ignoreHTTPSErrors: true,
      viewport: { width: viewport.width, height: viewport.height },
    });
    const page = await context.newPage();
    await page.goto(baseUrl, { waitUntil: 'networkidle' });

    for (const theme of themes) {
      if (theme === 'dark') {
        await page.getByRole('button', { name: 'Attiva tema scuro' }).click();
      }

      for (const view of views) {
        await page.getByRole('button', { name: view.navLabel, exact: true }).first().click();
        await page.waitForTimeout(600);

        const label = `${view.id} ${viewport.name} ${theme}`;
        const file = `${outputDir}/${view.id}-${viewport.name}-${theme}.png`;
        await page.screenshot({ path: file, fullPage: true });

        reportIssues(label, await collectLayoutIssues(page));
      }

      if (theme === 'dark') {
        await page.getByRole('button', { name: 'Attiva tema chiaro' }).click();
      }
    }

    await context.close();
  }
} finally {
  await browser.close();
}

console.log(`Screenshot salvati in ${outputDir}/`);
process.exitCode = issuesCount > 0 ? 1 : 0;

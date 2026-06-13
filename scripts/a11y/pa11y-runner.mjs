import pa11y from 'pa11y';
import { chromium } from 'playwright';

const urls = process.argv.slice(2);

if (urls.length === 0) {
  console.error('Usage: node scripts/a11y/pa11y-runner.mjs <url> [url...]');
  process.exit(1);
}

let issuesCount = 0;

for (const url of urls) {
  const result = await pa11y(url, {
    chromeLaunchConfig: {
      executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || chromium.executablePath(),
      ignoreHTTPSErrors: true,
      args: ['--no-sandbox', '--disable-dev-shm-usage', '--ignore-certificate-errors'],
    },
    timeout: 60000,
    wait: 500,
  });

  if (result.issues.length === 0) {
    console.log(`${url}: 0 Pa11y issues found`);
    continue;
  }

  console.error(`${url}: ${result.issues.length} Pa11y issues found`);

  for (const issue of result.issues) {
    console.error(`- ${issue.type}: ${issue.code}`);
    console.error(`  ${issue.message}`);

    if (issue.selector) {
      console.error(`  ${issue.selector}`);
    }
  }

  issuesCount += result.issues.length;
}

process.exitCode = issuesCount > 0 ? 1 : 0;

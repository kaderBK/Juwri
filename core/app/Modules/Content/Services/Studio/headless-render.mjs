#!/usr/bin/env node
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * J18 — render one standalone HTML file with headless Chrome (puppeteer from
 * core/node_modules). Driven by Juwri\Modules\Content\Services\Studio\HeadlessChrome.
 *
 *   node headless-render.mjs <job.json>
 *
 * job = { mode: 'pdf'|'png', html, out, format?, viewport?, select?,
 *         chromePath?, noSandbox, timeoutMs }
 * pdf: paper `format`, zero margins, backgrounds printed.
 * png: `viewport` {width, height, deviceScaleFactor}; `select` → that element only.
 * Exit 0 with the file written to `out`; anything else is a failure (stderr).
 */
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const job = JSON.parse(readFileSync(process.argv[2], 'utf8'));

let puppeteer;
try {
  puppeteer = (await import('puppeteer')).default;
} catch (e) {
  console.error('puppeteer is not installed (npm install in core/)');
  process.exit(3);
}

const launch = { headless: true, timeout: job.timeoutMs, args: job.noSandbox ? ['--no-sandbox', '--disable-setuid-sandbox'] : [] };
if (job.chromePath) {
  launch.executablePath = job.chromePath;
}

const browser = await puppeteer.launch(launch);
try {
  const page = await browser.newPage();
  page.setDefaultTimeout(job.timeoutMs);
  if (job.viewport) {
    await page.setViewport(job.viewport);
  }
  await page.goto(pathToFileURL(job.html).href, { waitUntil: 'networkidle0', timeout: job.timeoutMs });
  await page.evaluate(() => (document.fonts ? document.fonts.ready.then(() => true) : true));

  if (job.mode === 'pdf') {
    await page.pdf({
      path: job.out,
      format: job.format || 'A4',
      printBackground: true,
      margin: { top: '0mm', right: '0mm', bottom: '0mm', left: '0mm' },
    });
  } else {
    let target = page;
    if (job.select) {
      target = await page.$(job.select);
      if (!target) {
        throw new Error('element not found: ' + job.select);
      }
    }
    await target.screenshot({ path: job.out, type: 'png', omitBackground: false });
  }
} catch (e) {
  console.error(String(e && e.message ? e.message : e));
  process.exitCode = 1;
} finally {
  await browser.close();
}

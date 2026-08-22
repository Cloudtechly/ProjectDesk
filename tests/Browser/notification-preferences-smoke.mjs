import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

import {
    launchChromium,
    prepareAuthenticationState,
} from './browser-runtime.mjs';

const baseURL = process.env.APP_URL || 'http://127.0.0.1:8000';
const artifacts = path.resolve('storage', 'app', 'browser-tests');
await mkdir(artifacts, { recursive: true });

const browser = await launchChromium();
const failures = [];
const storageState = await prepareAuthenticationState(browser, baseURL);

for (const viewport of [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'compact', width: 768, height: 900 },
]) {
    const context = await browser.newContext({
        viewport: { width: viewport.width, height: viewport.height },
        locale: 'ar-LY',
        timezoneId: 'Africa/Tripoli',
        storageState,
    });
    const page = await context.newPage();
    const errors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(message.text());
        }
    });
    page.on('pageerror', (error) => errors.push(error.message));

    try {
        await page.goto(`${baseURL}/settings/notifications`, {
            waitUntil: 'networkidle',
        });

        await page
            .getByRole('heading', { name: 'تفضيلات التنبيهات', exact: true })
            .waitFor();
        const switches = page.getByRole('switch');

        if ((await switches.count()) !== 4) {
            throw new Error('expected four notification switches');
        }

        const master = switches.first();
        await master.focus();
        const before = await master.isChecked();
        await page.keyboard.press('Space');

        if ((await master.isChecked()) === before) {
            throw new Error('keyboard did not toggle the master switch');
        }

        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
        );

        if (overflow > 1) {
            throw new Error(`document overflow ${overflow}px`);
        }

        await page.screenshot({
            path: path.join(
                artifacts,
                `notification-preferences-${viewport.name}.png`,
            ),
            fullPage: true,
        });

        if (errors.length > 0) {
            throw new Error(`console errors: ${errors.join(' | ')}`);
        }
    } catch (error) {
        failures.push(
            `${viewport.name}: ${error instanceof Error ? error.message : String(error)}`,
        );
    } finally {
        await context.close();
    }
}

await browser.close();

if (failures.length > 0) {
    console.error(JSON.stringify({ passed: false, failures }, null, 2));
    process.exit(1);
}

console.log(JSON.stringify({ passed: true, viewports: 2, artifacts }, null, 2));

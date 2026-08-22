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
const storageState = await prepareAuthenticationState(browser, baseURL);
const failures = [];

for (const viewport of [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'tablet', width: 1024, height: 900 },
    { name: 'compact', width: 768, height: 900 },
]) {
    const context = await browser.newContext({
        viewport: { width: viewport.width, height: viewport.height },
        locale: 'ar-LY',
        timezoneId: 'Africa/Tripoli',
        storageState,
    });
    const page = await context.newPage();
    const consoleErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    try {
        await page.goto(`${baseURL}/dashboard`, { waitUntil: 'networkidle' });
        await page
            .getByRole('heading', { level: 1, name: 'لوحة المتابعة' })
            .waitFor();

        for (const route of [
            '/dashboard',
            '/projects',
            '/tasks?view=kanban',
            '/clients',
            '/team',
            '/sales',
            '/data-center',
            '/settings',
        ]) {
            await page.goto(`${baseURL}${route}`, { waitUntil: 'networkidle' });
            const direction = await page.locator('html').getAttribute('dir');

            if (direction !== 'rtl') {
                throw new Error(`${route}: html dir is ${direction}`);
            }

            const overflow = await page.evaluate(
                () =>
                    document.documentElement.scrollWidth -
                    document.documentElement.clientWidth,
            );

            if (overflow > 1) {
                throw new Error(`${route}: document overflow ${overflow}px`);
            }
        }

        await page.goto(`${baseURL}/projects/1?tab=tasks`, {
            waitUntil: 'networkidle',
        });
        await page.getByRole('heading', { level: 1 }).waitFor();
        await page.getByText('مهام المشروع').waitFor();

        await page.goto(`${baseURL}/dashboard`, { waitUntil: 'networkidle' });
        const weekly = page.getByRole('region', {
            name: 'جدول المشاريع للأسبوع المختار',
        });

        if (await weekly.count()) {
            await weekly.screenshot({
                path: path.join(artifacts, `weekly-${viewport.name}.png`),
            });
        }

        await page.screenshot({
            path: path.join(artifacts, `dashboard-${viewport.name}.png`),
            fullPage: true,
        });

        if (consoleErrors.length > 0) {
            throw new Error(`console errors: ${consoleErrors.join(' | ')}`);
        }
    } catch (error) {
        failures.push(
            `${viewport.name}: ${error instanceof Error ? error.message : String(error)}`,
        );
        await page.screenshot({
            path: path.join(artifacts, `failure-${viewport.name}.png`),
            fullPage: true,
        });
    } finally {
        await context.close();
    }
}

await browser.close();

if (failures.length > 0) {
    console.error(JSON.stringify({ passed: false, failures }, null, 2));
    process.exit(1);
}

console.log(JSON.stringify({ passed: true, viewports: 3, artifacts }, null, 2));

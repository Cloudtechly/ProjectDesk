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
        await page.goto(`${baseURL}/dashboard`, { waitUntil: 'networkidle' });

        const trigger = page.getByRole('button', { name: /التنبيهات/ });
        await trigger.click();
        const dialog = page.getByRole('dialog', { name: 'مركز التنبيهات' });
        await dialog.waitFor();
        await page
            .getByRole('button', { name: 'إغلاق مركز التنبيهات' })
            .waitFor();

        const notificationItems = dialog.locator(
            '.cloudtech-notification-item',
        );

        if ((await notificationItems.count()) > 0) {
            const firstItem = notificationItems.first();
            const tagName = await firstItem.evaluate(
                (element) => element.tagName,
            );

            if (
                tagName !== 'BUTTON' ||
                (await firstItem.getAttribute('href'))
            ) {
                throw new Error(
                    'notifications must open through a POST button, not a raw link',
                );
            }
        }

        const focusedAfterOpen = await page.evaluate(
            () => document.activeElement?.getAttribute('aria-label') ?? '',
        );

        if (focusedAfterOpen !== 'إغلاق مركز التنبيهات') {
            throw new Error(`unexpected initial focus: ${focusedAfterOpen}`);
        }

        await page.keyboard.press('Shift+Tab');
        const focusedAfterBackwardWrap = await page.evaluate(
            () => document.activeElement?.textContent?.trim() ?? '',
        );

        if (!focusedAfterBackwardWrap.includes('فتح لوحة المتابعة')) {
            throw new Error(
                `focus did not wrap backward: ${focusedAfterBackwardWrap}`,
            );
        }

        await page.keyboard.press('Tab');
        const focusedAfterForwardWrap = await page.evaluate(
            () => document.activeElement?.getAttribute('aria-label') ?? '',
        );

        if (focusedAfterForwardWrap !== 'إغلاق مركز التنبيهات') {
            throw new Error(
                `focus did not wrap forward: ${focusedAfterForwardWrap}`,
            );
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
                `notification-center-${viewport.name}.png`,
            ),
            fullPage: true,
        });

        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden' });
        const triggerFocused = await trigger.evaluate(
            (element) => element === document.activeElement,
        );

        if (!triggerFocused) {
            throw new Error('focus did not return to the notification trigger');
        }

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

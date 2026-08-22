import process from 'node:process';

import {
    launchChromium,
    prepareAuthenticationState,
} from './browser-runtime.mjs';

const baseURL = process.env.APP_URL || 'http://127.0.0.1:8000';
const browser = await launchChromium();
const storageState = await prepareAuthenticationState(browser, baseURL);
const failures = [];

async function assertDialogFits(page, width) {
    const dialog = page.getByRole('dialog');
    await dialog.waitFor();
    const rect = await dialog.evaluate((element) => {
        const bounds = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return {
            left: bounds.left,
            right: bounds.right,
            top: bounds.top,
            bottom: bounds.bottom,
            transform: style.transform,
            translate: style.translate,
            position: style.position,
            inset: style.inset,
            className: element.className,
        };
    });

    if (rect.left < -1 || rect.right > width + 1 || rect.top < -1) {
        throw new Error(
            `dialog exceeds ${width}px viewport: ${JSON.stringify(rect)}`,
        );
    }
}

for (const width of [360, 512]) {
    const context = await browser.newContext({
        viewport: { width, height: 760 },
        locale: 'ar-LY',
        timezoneId: 'Africa/Tripoli',
        reducedMotion: 'reduce',
        storageState,
    });
    const page = await context.newPage();

    try {
        await page.goto(`${baseURL}/tasks/create`, {
            waitUntil: 'networkidle',
        });
        await assertDialogFits(page, width);

        const title = page.getByRole('dialog').getByLabel('عنوان المهمة');
        await title.fill('تغيير غير محفوظ');
        const unloadGuard = await page.evaluate(() => {
            const event = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(event);

            return event.defaultPrevented;
        });

        if (!unloadGuard) {
            throw new Error(`task beforeunload guard missing at ${width}px`);
        }

        page.once('dialog', (dialog) => dialog.dismiss());
        await page.keyboard.press('Escape');
        await page.getByRole('dialog').waitFor();

        page.once('dialog', (dialog) => dialog.accept());
        await page.keyboard.press('Escape');
        await page.waitForURL('**/tasks');
    } catch (error) {
        failures.push(`${width}px: ${error.message}`);
    } finally {
        await context.close();
    }
}

const context = await browser.newContext({
    viewport: { width: 512, height: 800 },
    locale: 'ar-LY',
    timezoneId: 'Africa/Tripoli',
    reducedMotion: 'reduce',
    storageState,
});
const page = await context.newPage();

try {
    await page.goto(`${baseURL}/sales`, { waitUntil: 'networkidle' });
    const newDocument = page.getByRole('button', {
        name: 'قالب فاتورة جديد',
    });
    await newDocument.click();
    const builder = page.getByRole('dialog');
    await builder.getByLabel(/عنوان الفاتورة/).fill('مسودة غير محفوظة');

    page.once('dialog', (dialog) => dialog.dismiss());
    await builder.getByRole('button', { name: 'إغلاق', exact: true }).click();
    await builder.waitFor();

    page.once('dialog', (dialog) => dialog.accept());
    await builder.getByRole('button', { name: 'إغلاق', exact: true }).click();
    await builder.waitFor({ state: 'hidden' });

    const focusReturned = await newDocument.evaluate(
        (element) => document.activeElement === element,
    );

    if (!focusReturned) {
        throw new Error('sales builder did not return focus to its opener');
    }

    const search = page.locator('#global-search');

    if ((await search.getAttribute('aria-controls')) !== null) {
        throw new Error('closed global search exposes aria-controls');
    }

    const notifications = page.locator('.cloudtech-notification-trigger');

    if ((await notifications.getAttribute('aria-expanded')) === 'true') {
        await notifications.click();
    }

    if ((await notifications.getAttribute('aria-controls')) !== null) {
        throw new Error('closed notifications expose aria-controls');
    }

    await notifications.click();
    const controlsId = await notifications.getAttribute('aria-controls');

    if (!controlsId || (await page.locator(`#${controlsId}`).count()) !== 1) {
        throw new Error('open notifications do not reference their panel');
    }
} catch (error) {
    failures.push(`sales/a11y: ${error.message}`);
} finally {
    await context.close();
    await browser.close();
}

if (failures.length > 0) {
    throw new Error(failures.join('\n'));
}

console.log(JSON.stringify({ passed: true, widths: [360, 512], guards: true }));

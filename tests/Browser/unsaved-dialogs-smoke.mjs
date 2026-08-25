import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

import {
    launchChromium,
    prepareAuthenticationState,
} from './browser-runtime.mjs';

const baseURL = process.env.APP_URL || 'http://127.0.0.1:8000';
const browser = await launchChromium();
const storageState = await prepareAuthenticationState(browser, baseURL);
const failures = [];
const artifacts = path.resolve('storage', 'app', 'browser-tests');
await mkdir(artifacts, { recursive: true });

async function clickRealOverlay(page, label) {
    const point = await page.evaluate(() => {
        const overlay = document.querySelector('[data-slot="dialog-overlay"]');
        const candidates = [
            [4, 4],
            [window.innerWidth - 4, 4],
            [4, window.innerHeight - 4],
            [window.innerWidth - 4, window.innerHeight - 4],
        ];

        for (const [x, y] of candidates) {
            const target = document.elementFromPoint(x, y);

            if (
                overlay &&
                target &&
                (target === overlay || overlay.contains(target))
            ) {
                return { x, y };
            }
        }

        return null;
    });

    if (!point) {
        throw new Error(
            `${label}: no physically clickable overlay point exists`,
        );
    }

    await page.mouse.click(point.x, point.y);
}

async function assertUnsavedDialog({
    page,
    route,
    open,
    field,
    value,
    label,
    navigationHref,
}) {
    await page.goto(`${baseURL}${route}`, { waitUntil: 'networkidle' });

    const opener = page.getByRole('button', { name: open }).first();
    await opener.click();

    const dialog = page.getByRole('dialog');
    await dialog.waitFor();
    await dialog.getByLabel(field, { exact: true }).fill(value);

    const unloadGuard = await page.evaluate(() => {
        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);

        return event.defaultPrevented;
    });

    if (!unloadGuard) {
        throw new Error(`${label}: beforeunload guard is missing`);
    }

    const rejectedEscape = new Promise((resolve) => {
        page.once('dialog', async (confirmation) => {
            await confirmation.dismiss();
            resolve();
        });
    });
    await page.keyboard.press('Escape');
    await rejectedEscape;
    await dialog.waitFor();

    if (
        (await dialog.getByLabel(field, { exact: true }).inputValue()) !== value
    ) {
        throw new Error(`${label}: rejected Escape discarded the draft`);
    }

    const rejectedOverlayClose = new Promise((resolve) => {
        page.once('dialog', async (confirmation) => {
            await confirmation.dismiss();
            resolve();
        });
    });
    await clickRealOverlay(page, label);
    await rejectedOverlayClose;
    await dialog.waitFor();

    const acceptedClose = new Promise((resolve) => {
        page.once('dialog', async (confirmation) => {
            await confirmation.accept();
            resolve();
        });
    });
    await dialog.getByRole('button', { name: 'إغلاق' }).click();
    await acceptedClose;
    await dialog.waitFor({ state: 'hidden' });

    const openerHandle = await opener.elementHandle();
    await page.waitForFunction(
        (element) => document.activeElement === element,
        openerHandle,
    );

    await opener.click();
    await dialog.waitFor();
    const navigationDraft = `${value} - تنقل`;
    await dialog.getByLabel(field, { exact: true }).fill(navigationDraft);

    const originalUrl = page.url();
    const navigationLink = page
        .locator(`main a[href="${navigationHref}"]`)
        .first();
    const rejectedNavigation = new Promise((resolve) => {
        page.once('dialog', async (confirmation) => {
            await confirmation.dismiss();
            resolve();
        });
    });
    await navigationLink.evaluate((element) => element.click());
    await rejectedNavigation;
    await page.waitForFunction(
        (url) => window.location.href === url,
        originalUrl,
    );

    if (page.url() !== originalUrl || !(await dialog.isVisible())) {
        throw new Error(`${label}: rejected navigation discarded the dialog`);
    }

    if (
        (await dialog.getByLabel(field, { exact: true }).inputValue()) !==
        navigationDraft
    ) {
        throw new Error(`${label}: rejected navigation discarded the draft`);
    }

    const acceptedNavigation = page.waitForURL(
        new URL(navigationHref, baseURL).toString(),
    );
    page.once('dialog', (confirmation) => confirmation.accept());
    await navigationLink.evaluate((element) => element.click());
    await acceptedNavigation;
}

async function assertHistoryGuard(page, width) {
    await page.goto(`${baseURL}/dashboard`, { waitUntil: 'networkidle' });

    const projectsLink = page.locator('main a[href="/projects"]').first();
    await projectsLink.evaluate((element) => element.click());
    await page.waitForURL('**/projects');

    const opener = page.getByRole('button', { name: 'إنشاء مشروع' }).first();
    await opener.click();

    const dialog = page.getByRole('dialog');
    const field = dialog.getByLabel('رمز المشروع', { exact: true });
    const draft = `HISTORY-${width}`;
    await field.fill(draft);

    const rejectedHistory = new Promise((resolve) => {
        page.once('dialog', async (confirmation) => {
            await confirmation.dismiss();
            resolve();
        });
    });
    await page.evaluate(() => window.history.back());
    await rejectedHistory;
    await page.waitForFunction(() => window.location.pathname === '/projects');

    if (!(await dialog.isVisible()) || (await field.inputValue()) !== draft) {
        throw new Error(
            `${width}px project create: rejected browser Back discarded the draft (url=${page.url()}, visible=${await dialog.isVisible()}, value=${await field.inputValue()})`,
        );
    }

    const acceptedHistory = new Promise((resolve) => {
        page.once('dialog', async (confirmation) => {
            await confirmation.accept();
            resolve();
        });
    });
    const destination = page.waitForURL('**/dashboard');
    await page.evaluate(() => window.history.back());
    await acceptedHistory;
    await destination;
}

for (const width of [360, 768]) {
    const context = await browser.newContext({
        viewport: { width, height: 900 },
        locale: 'ar-LY',
        timezoneId: 'Africa/Tripoli',
        reducedMotion: 'reduce',
        storageState,
    });
    const page = await context.newPage();
    await context.tracing.start({ screenshots: true, snapshots: true });

    try {
        await page.goto(`${baseURL}/clients`, { waitUntil: 'networkidle' });
        const clientRoute = await page
            .locator('.client-card h2 a[href^="/clients/"]')
            .first()
            .getAttribute('href');

        if (!clientRoute) {
            throw new Error('no client record is available for dialog checks');
        }

        await assertUnsavedDialog({
            page,
            route: '/projects',
            open: 'إنشاء مشروع',
            field: 'رمز المشروع',
            value: `UNSAVED-${width}`,
            label: `${width}px project create`,
            navigationHref: '/projects/1',
        });

        await assertUnsavedDialog({
            page,
            route: '/projects/1',
            open: 'تعديل المشروع',
            field: 'اسم المشروع',
            value: `مشروع غير محفوظ ${width}`,
            label: `${width}px project edit`,
            navigationHref: '/projects',
        });

        await assertUnsavedDialog({
            page,
            route: '/projects/1?tab=requirements',
            open: 'إضافة متطلب',
            field: 'العنوان',
            value: `متطلب غير محفوظ ${width}`,
            label: `${width}px requirement`,
            navigationHref: '/projects',
        });

        await assertUnsavedDialog({
            page,
            route: clientRoute,
            open: 'إضافة جهة اتصال',
            field: 'الاسم',
            value: `جهة اتصال غير محفوظة ${width}`,
            label: `${width}px contact`,
            navigationHref: '/clients',
        });

        await assertUnsavedDialog({
            page,
            route: '/team',
            open: 'إضافة عضو',
            field: 'الاسم',
            value: `عضو غير محفوظ ${width}`,
            label: `${width}px member`,
            navigationHref: '/projects/1',
        });

        await assertHistoryGuard(page, width);
    } catch (error) {
        failures.push(`${width}px: ${error.message}`);

        await page.screenshot({
            path: path.join(artifacts, `unsaved-dialogs-${width}-failure.png`),
            fullPage: true,
        });
        await context.tracing.stop({
            path: path.join(artifacts, `unsaved-dialogs-${width}-trace.zip`),
        });
    } finally {
        if (!failures.some((failure) => failure.startsWith(`${width}px:`))) {
            await context.tracing.stop();
        }

        await context.close();
    }
}

await browser.close();

if (failures.length > 0) {
    throw new Error(failures.join('\n'));
}

console.log(
    JSON.stringify({
        passed: true,
        widths: [360, 768],
        dialogs: [
            'project create',
            'project edit',
            'requirement',
            'contact',
            'member',
        ],
        guards: [
            'Escape',
            'overlay',
            'close',
            'navigation',
            'browser Back',
            'beforeunload',
            'focus return',
        ],
    }),
);

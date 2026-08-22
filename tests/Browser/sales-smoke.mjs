import { mkdir, writeFile } from 'node:fs/promises';
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
const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
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
    await page.goto(`${baseURL}/sales`, { waitUntil: 'networkidle' });
    await page
        .getByRole('heading', { level: 1, name: 'قوالب الفواتير' })
        .waitFor();

    await page.getByRole('button', { name: 'قالب فاتورة جديد' }).click();
    const dialog = page.getByRole('dialog');
    const title = `قالب اختبار المتصفح ${Date.now()}`;
    await dialog.getByLabel(/عنوان الفاتورة/).fill(title);
    await dialog.getByLabel(/اسم البند/).fill('تحليل تجربة المستخدم');
    await dialog.getByLabel(/الكمية/).fill('2');
    await dialog.getByLabel(/^السعر/).fill('1000');
    await dialog.getByRole('button', { name: 'إضافة بند' }).click();
    await dialog
        .getByLabel(/اسم البند/)
        .nth(1)
        .fill('تنفيذ الواجهات');
    await dialog
        .getByLabel(/الكمية/)
        .nth(1)
        .fill('3');
    await dialog
        .getByLabel(/^السعر/)
        .nth(1)
        .fill('500');
    await dialog.getByLabel(/الخصم/).fill('10');
    await dialog.getByLabel(/الضريبة/).fill('15');
    await dialog.screenshot({
        path: path.join(artifacts, 'sales-builder-desktop.png'),
    });
    await dialog.getByRole('button', { name: 'حفظ القالب' }).click();
    await dialog.waitFor({ state: 'hidden' });
    await page.getByText(title, { exact: true }).waitFor();

    const originalRow = page
        .getByRole('heading', { level: 2, name: title, exact: true })
        .locator('xpath=ancestor::article');
    const pdfHref = await originalRow
        .getByRole('link', { name: /تنزيل معاينة PDF/ })
        .getAttribute('href');

    if (!pdfHref) {
        throw new Error('invoice template PDF link is missing');
    }

    const pdfResponse = await page.request.get(`${baseURL}${pdfHref}`);

    if (
        !pdfResponse.ok() ||
        !pdfResponse.headers()['content-type']?.includes('application/pdf')
    ) {
        throw new Error(
            `invoice template PDF failed: ${pdfResponse.status()} ${pdfResponse.headers()['content-type']}`,
        );
    }

    await writeFile(
        path.join(artifacts, 'invoice-template-reference.pdf'),
        await pdfResponse.body(),
    );

    await originalRow.getByRole('button', { name: /فتح قالب/ }).click();
    await dialog.getByTestId('invoice-sheet').first().waitFor();
    page.once('dialog', (confirmation) => confirmation.accept());
    await dialog.getByRole('button', { name: 'إنشاء نسخة' }).click();
    await dialog.waitFor({ state: 'hidden' });
    await page.getByText(`نسخة من ${title}`, { exact: true }).waitFor();

    await page
        .getByRole('heading', { level: 2, name: title, exact: true })
        .locator('xpath=ancestor::article')
        .getByRole('button', { name: /فتح قالب/ })
        .click();
    page.once('dialog', (confirmation) => confirmation.accept());
    await dialog.getByRole('button', { name: 'أرشفة القالب' }).click();
    await dialog.waitFor({ state: 'hidden' });
    await page.goto(`${baseURL}/sales?status=archived`, {
        waitUntil: 'networkidle',
    });
    await page.getByText(title, { exact: true }).waitFor();
    await page
        .getByRole('heading', { level: 2, name: title, exact: true })
        .locator('xpath=ancestor::article')
        .getByRole('button', { name: /فتح قالب/ })
        .click();
    page.once('dialog', (confirmation) => confirmation.accept());
    await dialog.getByRole('button', { name: 'استعادة القالب' }).click();
    await dialog.waitFor({ state: 'hidden' });
    await page.getByText(title, { exact: true }).waitFor();

    for (const viewport of [
        { width: 1440, height: 900, name: 'desktop' },
        { width: 1024, height: 900, name: 'tablet' },
        { width: 768, height: 900, name: 'compact' },
    ]) {
        await page.setViewportSize({
            width: viewport.width,
            height: viewport.height,
        });
        await page.waitForTimeout(100);
        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
        );

        if (overflow > 1) {
            throw new Error(
                `sales ${viewport.name}: document overflow ${overflow}px`,
            );
        }

        await page.screenshot({
            path: path.join(artifacts, `sales-${viewport.name}.png`),
            fullPage: true,
        });
    }

    if (errors.length > 0) {
        throw new Error(`console errors: ${errors.join(' | ')}`);
    }

    console.log(JSON.stringify({ passed: true, title, artifacts }, null, 2));
} finally {
    await context.close();
    await browser.close();
}

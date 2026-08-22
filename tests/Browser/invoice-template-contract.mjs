import assert from 'node:assert/strict';
import process from 'node:process';

import {
    launchChromium,
    prepareAuthenticationState,
} from './browser-runtime.mjs';

const baseURL = process.env.APP_URL || 'http://127.0.0.1:8000';
const browser = await launchChromium();
const storageState = await prepareAuthenticationState(browser, baseURL);
const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'ar-LY',
    timezoneId: 'Africa/Tripoli',
    storageState,
});
const page = await context.newPage();
const runtimeErrors = [];

page.on('console', (message) => {
    if (message.type() === 'error') {
        runtimeErrors.push(message.text());
    }
});
page.on('pageerror', (error) => runtimeErrors.push(error.message));

function normalized(values) {
    return values.map((value) => value.replace(/\s+/gu, ' ').trim());
}

async function settleResponsiveLayout() {
    await page.evaluate(async () => {
        await document.fonts.ready;
        await new Promise((resolve) =>
            requestAnimationFrame(() => requestAnimationFrame(resolve)),
        );
    });
}

async function assertInvoiceStructure(sheets) {
    assert.equal(await sheets.count(), 2, 'eight items must render two sheets');

    const requiredRegions = [
        'invoice-banner',
        'invoice-logo',
        'invoice-client',
        'invoice-reference-list',
        'invoice-lines',
        'invoice-footer',
        'invoice-page-number',
    ];

    for (let pageIndex = 0; pageIndex < 2; pageIndex += 1) {
        const sheet = sheets.nth(pageIndex);
        assert.equal(
            await sheet.getAttribute('data-page'),
            String(pageIndex + 1),
            `sheet ${pageIndex + 1} must expose its one-based page number`,
        );

        for (const testId of requiredRegions) {
            assert.equal(
                await sheet.getByTestId(testId).count(),
                1,
                `sheet ${pageIndex + 1} must contain one ${testId}`,
            );
        }
    }

    const firstSheet = sheets.nth(0);
    const lastSheet = sheets.nth(1);
    assert.equal(
        await firstSheet
            .locator('[data-testid="invoice-lines"] tbody > tr')
            .count(),
        7,
        'the first sheet must contain seven line items',
    );
    assert.equal(
        await lastSheet
            .locator('[data-testid="invoice-lines"] tbody > tr')
            .count(),
        1,
        'the second sheet must contain the eighth line item',
    );
    assert.equal(
        await firstSheet.getByTestId('invoice-totals').count(),
        0,
        'totals must not appear before the final sheet',
    );
    assert.equal(
        await lastSheet.getByTestId('invoice-totals').count(),
        1,
        'totals must appear once on the final sheet',
    );

    assert.deepEqual(
        normalized(
            await firstSheet
                .locator('[data-testid="invoice-lines"] thead th')
                .allTextContents(),
        ),
        ['البيان والتفاصيل', 'العدد', 'الوحدة', 'السعر', 'الإجمالي'],
        'the line table must retain the five-column reference contract',
    );
    assert.match(await firstSheet.innerText(), /استكمال البنود/u);
    assert.doesNotMatch(await firstSheet.innerText(), /ملاحظة قبول العقد/u);
    assert.match(await lastSheet.innerText(), /ملاحظة قبول العقد/u);
    assert.equal(
        (
            await firstSheet.getByTestId('invoice-page-number').innerText()
        ).trim(),
        '1 / 2',
    );
    assert.equal(
        (await lastSheet.getByTestId('invoice-page-number').innerText()).trim(),
        '2 / 2',
    );

    const firstSheetText = await firstSheet.innerText();
    const lastSheetText = await lastSheet.innerText();

    for (let index = 1; index <= 7; index += 1) {
        assert.match(firstSheetText, new RegExp(`خدمة قبول ${index}`, 'u'));
        assert.doesNotMatch(
            lastSheetText,
            new RegExp(`خدمة قبول ${index}`, 'u'),
        );
    }

    assert.doesNotMatch(firstSheetText, /خدمة قبول 8/u);
    assert.match(lastSheetText, /خدمة قبول 8/u);

    const referenceText = await firstSheet
        .getByTestId('invoice-reference-list')
        .innerText();
    assert.match(referenceText, /REF-BROWSER-42/u);
    assert.match(referenceText, /14\/08\/2026/u);
    assert.match(referenceText, /28\/08\/2026/u);

    const logo = firstSheet.getByTestId('invoice-logo');
    assert.equal(await logo.getAttribute('alt'), 'CloudTech');
    assert.match(await logo.getAttribute('src'), /cloudtech-logo\.svg$/u);

    assert.deepEqual(
        (
            await lastSheet
                .getByTestId('invoice-totals')
                .locator(':scope > div > dd')
                .allTextContents()
        ).map((value) => value.replace(/[^0-9]/gu, '')),
        ['3502', '3502', '47277', '362457'],
        'totals must retain their numeric meaning across locale-specific formatting',
    );
    assert.equal(
        (
            await firstSheet
                .locator('[data-testid="invoice-lines"] tbody > tr')
                .first()
                .locator('td')
                .nth(4)
                .innerText()
        ).replace(/[^0-9]/gu, ''),
        '2',
        '0.401 × 5.00 must use the same fixed-decimal truncation as the PDF backend',
    );
}

async function assertResponsiveLayout(sheets, viewport) {
    await page.setViewportSize({
        width: viewport.width,
        height: viewport.height,
    });

    const previewTab = page.locator('#sales-builder-preview-tab');

    if (await previewTab.isVisible()) {
        await previewTab.click();
    }

    await sheets.first().waitFor({ state: 'visible' });
    await settleResponsiveLayout();

    const measurements = await page.evaluate(() => ({
        viewportWidth: window.innerWidth,
        documentOverflow:
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
        sheets: Array.from(
            document.querySelectorAll('[data-testid="invoice-sheet"]'),
            (sheet) => {
                const rectangle = sheet.getBoundingClientRect();

                return {
                    width: rectangle.width,
                    left: rectangle.left,
                    right: rectangle.right,
                    intrinsicOverflow: sheet.scrollWidth - sheet.clientWidth,
                };
            },
        ),
    }));

    assert.ok(
        measurements.documentOverflow <= 1,
        `${viewport.name}: document overflowed horizontally by ${measurements.documentOverflow}px`,
    );
    assert.equal(
        measurements.sheets.length,
        2,
        `${viewport.name}: invoice pagination changed at this viewport`,
    );

    for (const [index, sheet] of measurements.sheets.entries()) {
        assert.ok(
            sheet.width > 0,
            `${viewport.name}: sheet ${index + 1} is hidden`,
        );
        assert.ok(
            sheet.left >= -1,
            `${viewport.name}: sheet ${index + 1} starts outside the viewport`,
        );
        assert.ok(
            sheet.right <= measurements.viewportWidth + 1,
            `${viewport.name}: sheet ${index + 1} exceeds the viewport by ${sheet.right - measurements.viewportWidth}px`,
        );
        assert.ok(
            sheet.intrinsicOverflow <= 1,
            `${viewport.name}: sheet ${index + 1} content overflows by ${sheet.intrinsicOverflow}px`,
        );
    }
}

try {
    await page.goto(`${baseURL}/sales`, { waitUntil: 'networkidle' });
    await page
        .getByRole('heading', { level: 1, name: 'قوالب الفواتير' })
        .waitFor();
    await page.getByRole('button', { name: 'قالب فاتورة جديد' }).click();

    const dialog = page.getByRole('dialog');
    await dialog.getByLabel(/عنوان الفاتورة/u).fill('قالب قبول بنية الفاتورة');
    await dialog.getByLabel(/تاريخ الإصدار/u).fill('2026-08-14');
    await dialog.getByLabel(/تاريخ الاستحقاق/u).fill('2026-08-28');
    await dialog.getByLabel(/المرجع/u).fill('REF-BROWSER-42');
    await dialog.getByLabel(/الخصم/u).fill('10');
    await dialog.getByLabel(/الضريبة/u).fill('15');
    await dialog.getByLabel(/ملاحظة الأسعار/u).fill('ملاحظة قبول العقد');

    const editorLines = dialog.locator('.sales-line-item');

    for (let index = 0; index < 8; index += 1) {
        if (index > 0) {
            await dialog.getByRole('button', { name: 'إضافة بند' }).click();
            await editorLines.nth(index).waitFor();
        }

        const editorLine = editorLines.nth(index);
        await editorLine
            .getByLabel(/اسم البند/u)
            .fill(`خدمة قبول ${index + 1}`);
        await editorLine.getByLabel(/وصف مختصر/u).fill(`وصف قبول ${index + 1}`);
        await editorLine
            .getByLabel(/الكمية/u)
            .fill(index === 0 ? '0.401' : '1');
        await editorLine.getByLabel(/الوحدة/u).selectOption('مشروع');
        await editorLine
            .getByLabel(/السعر/u)
            .fill(index === 0 ? '5' : String((index + 1) * 100));
    }

    const sheets = dialog.getByTestId('invoice-sheet');
    await sheets.nth(1).waitFor();
    await settleResponsiveLayout();
    await assertInvoiceStructure(sheets);

    for (const viewport of [
        { width: 1440, height: 900, name: '1440px' },
        { width: 768, height: 900, name: '768px' },
        { width: 360, height: 800, name: '360px' },
    ]) {
        await assertResponsiveLayout(sheets, viewport);
    }

    assert.deepEqual(
        runtimeErrors,
        [],
        `browser errors: ${runtimeErrors.join(' | ')}`,
    );
    console.log(
        JSON.stringify(
            {
                passed: true,
                pages: 2,
                rowsPerPage: [7, 1],
                responsiveWidths: [1440, 768, 360],
            },
            null,
            2,
        ),
    );
} finally {
    await context.close();
    await browser.close();
}

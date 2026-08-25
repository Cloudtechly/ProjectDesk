import { Buffer } from 'node:buffer';
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
    await page.goto(`${baseURL}/projects/1?tab=requirements`, {
        waitUntil: 'networkidle',
    });

    const unique = Date.now();
    const requirementTitle = `متطلب اختبار ${unique}`;
    await page.getByRole('button', { name: 'إضافة متطلب' }).click();
    let dialog = page.getByRole('dialog');
    await dialog.getByLabel('العنوان').fill(requirementTitle);
    await dialog.getByLabel('الحالة').selectOption({ index: 1 });
    await dialog
        .getByLabel('معايير القبول')
        .fill('اعتماد النتيجة من مدير المشروع.');
    await dialog.getByRole('button', { name: 'حفظ المتطلب' }).click();
    await page.getByText(requirementTitle, { exact: true }).waitFor();

    let requirementRow = page.locator('.project-record-list li').filter({
        hasText: requirementTitle,
    });
    const editRequirement = requirementRow.getByRole('button', {
        name: 'تعديل المتطلب',
    });
    await editRequirement.focus();
    await editRequirement.press('Enter');
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('العنوان').waitFor();
    await page.waitForFunction(() =>
        document.activeElement?.closest('[role="dialog"]'),
    );
    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'hidden' });
    await page.waitForFunction(
        (element) => element === document.activeElement,
        await editRequirement.elementHandle(),
    );

    await editRequirement.click();
    dialog = page.getByRole('dialog');
    const updatedRequirementTitle = `${requirementTitle} محدث`;
    await dialog.getByLabel('العنوان').fill(updatedRequirementTitle);
    await dialog.getByRole('button', { name: 'حفظ التعديلات' }).click();
    await page.getByText(updatedRequirementTitle, { exact: true }).waitFor();

    requirementRow = page.locator('.project-record-list li').filter({
        hasText: updatedRequirementTitle,
    });
    page.once('dialog', (confirmation) => confirmation.accept());
    await requirementRow
        .getByRole('button', { name: `أرشفة ${updatedRequirementTitle}` })
        .click();
    await page
        .getByText(updatedRequirementTitle, { exact: true })
        .waitFor({ state: 'hidden' });
    await page.getByRole('link', { name: 'عرض الأرشيف' }).click();
    await page.waitForURL((url) => url.searchParams.get('archived') === '1');
    await page.getByText(updatedRequirementTitle, { exact: true }).waitFor();
    requirementRow = page.locator('.project-record-list li').filter({
        hasText: updatedRequirementTitle,
    });
    const restoredRequirementsPage = page.waitForURL(
        (url) =>
            url.pathname === '/projects/1' &&
            url.searchParams.get('tab') === 'requirements' &&
            !url.searchParams.has('archived'),
    );
    await requirementRow
        .getByRole('button', { name: `استعادة ${updatedRequirementTitle}` })
        .click();
    await restoredRequirementsPage;
    await page.getByText(updatedRequirementTitle, { exact: true }).waitFor();
    const requirementLayout = await page
        .locator('.project-record-list')
        .evaluate((element) => ({
            display: getComputedStyle(element).display,
            width: element.getBoundingClientRect().width,
            parentDisplay: getComputedStyle(element.parentElement).display,
            parentWidth: element.parentElement.getBoundingClientRect().width,
        }));
    await page.screenshot({
        path: path.join(artifacts, 'project-requirements-desktop.png'),
        fullPage: true,
    });
    await page.setViewportSize({ width: 768, height: 900 });
    const hasHorizontalOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth > window.innerWidth + 1,
    );

    if (hasHorizontalOverflow) {
        throw new Error(
            'requirements workspace overflows horizontally at 768px',
        );
    }

    await page.screenshot({
        path: path.join(artifacts, 'project-requirements-768.png'),
        fullPage: true,
    });
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.goto(`${baseURL}/projects/1?tab=meetings`, {
        waitUntil: 'networkidle',
    });
    const meetingTitle = `اجتماع اختبار ${unique}`;
    const minutesSummary = `تمت مراجعة المتطلبات واعتماد خطة التنفيذ ${unique}.`;
    await page.getByRole('button', { name: 'جدولة اجتماع' }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('عنوان الاجتماع').fill(meetingTitle);
    await dialog.getByLabel('البداية').fill('2026-08-13T10:00');
    await dialog.getByLabel('النهاية').fill('2026-08-13T11:00');
    await dialog.getByLabel('المكان').fill('قاعة الاجتماعات');
    await dialog.getByLabel('جدول الأعمال').fill('مراجعة المتطلبات والقرارات.');
    await dialog.getByRole('button', { name: 'جدولة الاجتماع' }).click();
    await page.goto(`${baseURL}/projects/1?tab=meetings`, {
        waitUntil: 'networkidle',
    });
    await page.getByText(meetingTitle, { exact: true }).waitFor();

    const meetingRow = page.locator('.project-meeting-record').filter({
        hasText: meetingTitle,
    });
    await meetingRow.getByRole('button', { name: 'إضافة محضر' }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('ملخص الاجتماع').fill(minutesSummary);
    await dialog
        .getByLabel('القرارات')
        .fill('اعتماد النسخة الأولى والبدء بالتنفيذ.');
    await dialog
        .getByLabel('بنود العمل والمتابعة')
        .fill('إسناد المهام ومراجعتها خلال أسبوع.');
    await dialog.getByLabel('ملف المحضر (اختياري)').setInputFiles({
        name: `meeting-minutes-${unique}.pdf`,
        mimeType: 'application/pdf',
        buffer: Buffer.from(
            '%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n',
            'utf8',
        ),
    });
    await dialog.getByRole('button', { name: 'حفظ المحضر' }).click();
    await meetingRow.getByText(minutesSummary, { exact: true }).waitFor();
    await page.screenshot({
        path: path.join(artifacts, 'project-meetings-desktop.png'),
        fullPage: true,
    });

    await page.goto(`${baseURL}/projects/1?tab=timeline`, {
        waitUntil: 'networkidle',
    });
    await page.getByText(meetingTitle, { exact: true }).waitFor();

    if (errors.length > 0) {
        throw new Error(`console errors: ${errors.join(' | ')}`);
    }

    console.log(
        JSON.stringify(
            {
                passed: true,
                requirementTitle,
                meetingTitle,
                requirementLayout,
                artifacts,
            },
            null,
            2,
        ),
    );
} finally {
    await context.close();
    await browser.close();
}

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

const suffix = Date.now().toString().slice(-10);
const clientCode = `E2E-C-${suffix}`;
const clientName = `عميل رحلة القبول ${suffix}`;
const contactName = `مسؤول العميل ${suffix}`;
const projectCode = `E2E-P-${suffix}`;
const projectName = `مشروع رحلة القبول ${suffix}`;
const taskTitle = `أول مهمة للمشروع ${suffix}`;
const browser = await launchChromium();
const storageState = await prepareAuthenticationState(browser, baseURL);
const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    locale: 'ar-LY',
    timezoneId: 'Africa/Tripoli',
    reducedMotion: 'reduce',
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

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

try {
    await page.goto(`${baseURL}/clients/create`, {
        waitUntil: 'networkidle',
    });
    await page.getByLabel('رمز العميل').fill(clientCode);
    await page.getByLabel('اسم العميل').fill(clientName);
    await page
        .getByLabel('البريد الإلكتروني')
        .fill(`qa-${suffix}@example.test`);
    await page.getByLabel('الهاتف').fill(`+21891${suffix.slice(-7)}`);
    await page.getByRole('button', { name: 'حفظ العميل' }).click();
    await page.waitForURL(/\/clients\/\d+$/);
    await page.getByRole('heading', { level: 1, name: clientName }).waitFor();

    await page.getByRole('button', { name: 'إضافة جهة اتصال' }).first().click();
    const contactDialog = page.getByRole('dialog');
    await contactDialog.getByLabel('الاسم').fill(contactName);
    await contactDialog.getByLabel('المسمى أو الدور').fill('مدير المشروع');
    await contactDialog
        .getByLabel('البريد الإلكتروني')
        .fill(`contact-${suffix}@example.test`);
    await contactDialog
        .locator('input[name="is_primary"][type="checkbox"]')
        .check();
    assert(
        await contactDialog
            .locator('form')
            .evaluate((form) => form.checkValidity()),
        'نموذج جهة الاتصال غير صالح وفق تحقق المتصفح.',
    );
    await contactDialog
        .getByRole('button', { name: 'إضافة جهة الاتصال' })
        .click();

    try {
        await contactDialog.waitFor({ state: 'hidden', timeout: 10_000 });
    } catch {
        throw new Error(
            `تعذر حفظ جهة الاتصال: ${(await contactDialog.innerText()).replaceAll('\n', ' | ')}`,
        );
    }

    await page.getByText(contactName, { exact: true }).waitFor();

    await page.getByRole('link', { name: 'مشروع جديد لهذا العميل' }).click();
    await page.waitForURL(/\/projects\?.*create=1/);
    const projectDialog = page.getByRole('dialog');
    await projectDialog.getByLabel('رمز المشروع').fill(projectCode);
    await projectDialog.getByLabel('اسم المشروع').fill(projectName);
    await projectDialog.getByRole('button', { name: 'التالي' }).click();

    const clientSelect = projectDialog.locator('select[name="client_id"]');
    assert(
        (await clientSelect.locator('option:checked').textContent())?.trim() ===
            clientName,
        'إنشاء المشروع من العميل لم يحتفظ بسياق العميل.',
    );
    await projectDialog
        .locator('select[name="primary_contact_id"]')
        .selectOption({ label: contactName });
    await projectDialog.getByRole('button', { name: 'التالي' }).click();
    await projectDialog.getByLabel('الحالة').selectOption({ index: 1 });
    await projectDialog.getByLabel('تاريخ البداية').fill('2026-08-12');
    await projectDialog.getByLabel('تاريخ النهاية').fill('2026-08-26');
    await projectDialog.getByRole('button', { name: 'إنشاء المشروع' }).click();
    await page.waitForURL(/\/projects\/\d+$/);
    await page.getByRole('heading', { level: 1, name: projectName }).waitFor();

    const projectURL = new URL(page.url());
    const projectId = projectURL.pathname.split('/').filter(Boolean).at(-1);
    assert(projectId, 'تعذر تحديد المشروع المنشأ من عنوان الصفحة.');

    await page.goto(`${baseURL}/projects/${projectId}?tab=tasks`, {
        waitUntil: 'networkidle',
    });
    await page
        .getByText('لا توجد مهام مرتبطة بالمشروع حتى الآن.', {
            exact: true,
        })
        .waitFor();
    await page
        .locator(`a[href="/tasks/create?project=${projectId}"]`)
        .last()
        .click();
    await page.waitForURL(new RegExp(`/tasks/create\\?project=${projectId}`));
    await page.waitForLoadState('networkidle');

    const taskDialog = page.getByRole('dialog');
    assert(
        await taskDialog.isVisible(),
        `لم يفتح محرر المهمة في ${page.url()}: ${(await page.locator('main').innerText()).replaceAll('\n', ' | ')}`,
    );
    const projectSelect = taskDialog.locator('select').first();
    assert(await projectSelect.isDisabled(), 'مشروع المهمة السياقية غير مثبت.');
    assert(
        (await taskDialog.locator('input[name="project_id"]').inputValue()) ===
            projectId,
        'معرف المشروع المخفي لا يطابق سياق المهمة.',
    );
    assert(
        (
            await projectSelect.locator('option:checked').textContent()
        )?.trim() === projectName,
        'المهمة السياقية لا تشير إلى المشروع المنشأ.',
    );
    await taskDialog.getByLabel('عنوان المهمة').fill(taskTitle);
    await taskDialog.getByLabel('الحالة').selectOption({ index: 1 });
    await taskDialog.getByLabel('بداية المهمة').fill('2026-08-12T09:00');
    await taskDialog.getByLabel('نهاية المهمة').fill('2026-08-13T17:00');
    await taskDialog.getByRole('button', { name: 'حفظ المهمة' }).click();
    await page.waitForURL(/\/tasks(?:\?.*)?$/);

    await page.goto(`${baseURL}/tasks?q=${encodeURIComponent(taskTitle)}`, {
        waitUntil: 'networkidle',
    });
    await page.getByText(taskTitle, { exact: true }).waitFor();
    await page
        .getByRole('row', { name: new RegExp(taskTitle) })
        .getByText('غير مسندة', { exact: true })
        .waitFor();

    await page.goto(`${baseURL}/projects/${projectId}?tab=tasks`, {
        waitUntil: 'networkidle',
    });
    await page.getByText(taskTitle, { exact: true }).waitFor();

    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    assert(overflow <= 1, `تجاوز أفقي في رحلة القبول: ${overflow}px.`);
    assert(
        consoleErrors.length === 0,
        `أخطاء المتصفح: ${consoleErrors.join(' | ')}`,
    );

    await page.screenshot({
        path: path.join(artifacts, 'critical-journey-project-task.png'),
        fullPage: true,
    });

    console.log(
        JSON.stringify({
            passed: true,
            clientCode,
            projectCode,
            projectId,
            taskTitle,
        }),
    );
} catch (error) {
    await page.screenshot({
        path: path.join(artifacts, 'critical-journey-failure.png'),
        fullPage: true,
    });

    throw error;
} finally {
    await context.close();
    await browser.close();
}

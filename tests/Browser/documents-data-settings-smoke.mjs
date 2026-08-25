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
    acceptDownloads: true,
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

async function assertNoDocumentOverflow(label) {
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );

    if (overflow > 1) {
        throw new Error(`${label}: document overflow ${overflow}px`);
    }
}

async function waitForSuccess(text) {
    await page
        .locator('.cloudtech-alert.success')
        .filter({ hasText: text })
        .waitFor();
}

try {
    await page.goto(`${baseURL}/settings`, { waitUntil: 'networkidle' });
    await page
        .getByRole('heading', { level: 1, name: 'إعدادات النظام' })
        .waitFor();
    await page.getByRole('button', { name: /حالات سير العمل/ }).click();
    await page.getByRole('button', { name: 'حفظ الحالات' }).waitFor();
    await page.getByRole('button', { name: 'حفظ الحالات' }).click();
    await waitForSuccess('تم حفظ ترتيب حالات سير العمل وتخصيصها.');
    await assertNoDocumentOverflow('settings');
    await page.screenshot({
        path: path.join(artifacts, 'settings-workflows-desktop.png'),
        fullPage: true,
    });

    await page.goto(`${baseURL}/data-center`, { waitUntil: 'networkidle' });
    await page
        .getByRole('heading', { level: 1, name: 'مركز البيانات' })
        .waitFor();
    const unique = Date.now();
    await page.getByLabel('صيغة الملف').selectOption('csv');
    await page.locator('input[type="file"][accept*=".csv"]').setInputFiles({
        name: `clients-${unique}.csv`,
        mimeType: 'text/csv',
        buffer: Buffer.from(
            `code,name,email,phone,address,status\nBROWSER-${unique},عميل اختبار المتصفح,browser${unique}@example.test,+218910000001,طرابلس,active\n`,
            'utf8',
        ),
    });
    await page.getByRole('button', { name: 'فحص ومعاينة الملف' }).click();
    await waitForSuccess('تم فحص الملف، راجع المعاينة ثم نفّذ الاستيراد.');
    await page.getByRole('button', { name: 'تأكيد الاستيراد النهائي' }).click();
    await waitForSuccess(/تم استيراد .* سجلاً بنجاح/);
    await page.getByRole('button', { name: /النسخ والاستعادة/ }).click();
    await page.getByRole('button', { name: 'إنشاء نسخة الآن' }).click();
    await waitForSuccess('أُنشئت نسخة احتياطية وتحقق النظام من سلامتها.');
    await assertNoDocumentOverflow('data-center');
    await page.screenshot({
        path: path.join(artifacts, 'data-center-backup-desktop.png'),
        fullPage: true,
    });

    await page.goto(`${baseURL}/projects/1?tab=documents`, {
        waitUntil: 'networkidle',
    });
    await page
        .getByRole('heading', { level: 2, name: 'كراسة المتطلبات' })
        .waitFor();
    const title = `كراسة اختبار ${unique}`;
    const bookForm = page.locator('form.requirement-book-upload');
    await bookForm.locator('input[name="title"]').fill(title);
    await bookForm.locator('input[name="file"]').setInputFiles({
        name: `requirements-${unique}.pdf`,
        mimeType: 'application/pdf',
        buffer: Buffer.from(
            '%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n',
            'utf8',
        ),
    });
    await bookForm.getByRole('button', { name: 'رفع إصدار جديد' }).click();
    await waitForSuccess('تم رفع إصدار جديد من كراسة المتطلبات.');
    await page
        .locator('.requirement-book-versions article strong', {
            hasText: title,
        })
        .first()
        .waitFor();

    const attachmentUploader = page.locator('.project-file-uploader');
    await attachmentUploader.getByLabel('ربط المرفق بـ').selectOption('task');
    const taskTarget = attachmentUploader.getByLabel('المهمة');
    await taskTarget.locator('option').nth(1).waitFor({ state: 'attached' });
    const firstTaskId = await taskTarget
        .locator('option')
        .nth(1)
        .getAttribute('value');

    if (!firstTaskId) {
        throw new Error('The Documents attachment target picker has no task.');
    }

    await taskTarget.selectOption(firstTaskId);
    await page.locator('label.project-file-upload input').setInputFiles({
        name: `attachment-${unique}.pdf`,
        mimeType: 'application/pdf',
        buffer: Buffer.from(
            '%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n',
            'utf8',
        ),
    });
    await waitForSuccess('تم رفع المرفق وحفظه ضمن المشروع.');
    await page.getByText(`attachment-${unique}.pdf`).waitFor();
    await page
        .getByText(/^مهمة:/)
        .first()
        .waitFor();
    await assertNoDocumentOverflow('project-documents');
    await page.screenshot({
        path: path.join(artifacts, 'project-documents-desktop.png'),
        fullPage: true,
    });

    await page.setViewportSize({ width: 768, height: 900 });
    await page.reload({ waitUntil: 'networkidle' });
    await page
        .getByRole('heading', { level: 2, name: 'كراسة المتطلبات' })
        .waitFor();
    await assertNoDocumentOverflow('project-documents-compact');
    await page.screenshot({
        path: path.join(artifacts, 'project-documents-compact.png'),
        fullPage: true,
    });

    if (errors.length > 0) {
        throw new Error(`console errors: ${errors.join(' | ')}`);
    }

    console.log(
        JSON.stringify(
            {
                passed: true,
                importedClient: `BROWSER-${unique}`,
                requirementBook: title,
                artifacts,
            },
            null,
            2,
        ),
    );
} catch (error) {
    await page.screenshot({
        path: path.join(artifacts, 'documents-data-settings-failure.png'),
        fullPage: true,
    });
    console.error(error);
    process.exitCode = 1;
} finally {
    await context.close();
    await browser.close();
}

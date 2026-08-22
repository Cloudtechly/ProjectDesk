import process from 'node:process';

import {
    launchChromium,
    prepareAuthenticationState,
} from './browser-runtime.mjs';

const baseURL = process.env.APP_URL || 'http://127.0.0.1:8000';
const viewports = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'tablet', width: 768, height: 900 },
    { name: 'mobile', width: 360, height: 900 },
];

const dialogCases = [
    {
        label: 'team member',
        route: '/team',
        opener: { role: 'button', name: 'إضافة عضو' },
    },
    {
        label: 'project create',
        route: '/projects',
        opener: { role: 'button', name: 'إنشاء مشروع' },
    },
    {
        label: 'task create',
        route: '/tasks/create',
    },
    {
        label: 'requirement',
        route: '/projects/1?tab=requirements',
        opener: { role: 'button', name: 'إضافة متطلب' },
    },
    {
        label: 'invoice template builder',
        route: '/sales',
        opener: { role: 'button', name: 'قالب فاتورة جديد' },
    },
];

const browser = await launchChromium();
const storageState = await prepareAuthenticationState(browser, baseURL);
const failures = [];
const results = [];

function issue(label, message) {
    throw new Error(`${label}: ${message}`);
}

async function resolveClientRoute(page) {
    await page.goto(`${baseURL}/clients`, { waitUntil: 'networkidle' });
    const route = await page
        .locator('.client-card h2 a[href^="/clients/"]')
        .first()
        .getAttribute('href');

    if (!route) {
        throw new Error('no client record is available for dialog checks');
    }

    return route;
}

async function openDialog(page, testCase) {
    await page.goto(`${baseURL}${testCase.route}`, {
        waitUntil: 'networkidle',
    });

    if (testCase.opener) {
        await page
            .getByRole(testCase.opener.role, {
                name: testCase.opener.name,
                exact: true,
            })
            .first()
            .click();
    }

    const dialog = page.getByRole('dialog').last();
    await dialog.waitFor();

    return dialog;
}

async function auditDialog(page, dialog, viewport, label) {
    const layout = await dialog.evaluate((element, size) => {
        const rect = element.getBoundingClientRect();
        const rootStyle = getComputedStyle(document.documentElement);

        return {
            rect: {
                left: rect.left,
                right: rect.right,
                top: rect.top,
                bottom: rect.bottom,
                width: rect.width,
                height: rect.height,
            },
            viewport: size,
            overflow:
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
            fieldToken: rootStyle.getPropertyValue('--ct-field-border').trim(),
        };
    }, viewport);

    if (!layout.fieldToken) {
        issue(label, 'the portal form token --ct-field-border is unresolved');
    }

    if (
        layout.rect.left < -1 ||
        layout.rect.right > viewport.width + 1 ||
        layout.rect.top < -1 ||
        layout.rect.bottom > viewport.height + 1
    ) {
        issue(label, `dialog exceeds viewport: ${JSON.stringify(layout.rect)}`);
    }

    if (layout.overflow > 1) {
        issue(label, `document overflow is ${layout.overflow}px`);
    }

    const close = dialog.locator('[data-slot="dialog-close"]').last();
    await close.waitFor();
    const closeRect = await close.evaluate((element) => {
        const rect = element.getBoundingClientRect();

        return { width: rect.width, height: rect.height };
    });

    if (closeRect.width < 40 || closeRect.height < 40) {
        issue(
            label,
            `close hitbox is ${closeRect.width}x${closeRect.height}px`,
        );
    }

    const fields = dialog.locator(
        'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), select, textarea',
    );
    const visibleFields = [];

    for (let index = 0; index < (await fields.count()); index += 1) {
        const field = fields.nth(index);

        if (await field.isVisible()) {
            visibleFields.push(field);
        }
    }

    if (visibleFields.length === 0) {
        issue(label, 'dialog has no visible form fields');
    }

    for (let index = 0; index < visibleFields.length; index += 1) {
        const field = visibleFields[index];
        const audit = await field.evaluate((element) => {
            const style = getComputedStyle(element);
            const rect = element.getBoundingClientRect();
            const descriptor =
                element.getAttribute('name') ||
                element.getAttribute('id') ||
                element.getAttribute('type') ||
                element.tagName.toLowerCase();

            return {
                descriptor,
                border: Math.min(
                    Number.parseFloat(style.borderTopWidth),
                    Number.parseFloat(style.borderRightWidth),
                    Number.parseFloat(style.borderBottomWidth),
                    Number.parseFloat(style.borderLeftWidth),
                ),
                background: style.backgroundColor,
                height: rect.height,
                fontSize: Number.parseFloat(style.fontSize),
            };
        });

        if (audit.border < 1) {
            issue(label, `${audit.descriptor} border is ${audit.border}px`);
        }

        if (
            audit.background === 'transparent' ||
            audit.background === 'rgba(0, 0, 0, 0)'
        ) {
            issue(label, `${audit.descriptor} background is transparent`);
        }

        if (audit.height < 44) {
            issue(label, `${audit.descriptor} height is ${audit.height}px`);
        }

        if (audit.fontSize < 13) {
            issue(
                label,
                `${audit.descriptor} font size is ${audit.fontSize}px`,
            );
        }
    }

    let focusField;

    for (const field of visibleFields) {
        if (await field.isEnabled()) {
            focusField = field;
            break;
        }
    }

    if (!focusField) {
        issue(label, 'dialog has no focusable form field');
    }

    await focusField.evaluate((element) => element.blur());
    const beforeFocus = await focusField.evaluate((element) => {
        const style = getComputedStyle(element);

        return {
            borderColor: style.borderColor,
            boxShadow: style.boxShadow,
            outlineStyle: style.outlineStyle,
            outlineWidth: style.outlineWidth,
        };
    });
    await focusField.focus();
    const afterFocus = await focusField.evaluate((element) => {
        const style = getComputedStyle(element);

        return {
            borderColor: style.borderColor,
            boxShadow: style.boxShadow,
            outlineStyle: style.outlineStyle,
            outlineWidth: style.outlineWidth,
        };
    });
    const hasOutline =
        afterFocus.outlineStyle !== 'none' &&
        Number.parseFloat(afterFocus.outlineWidth) >= 1;
    const hasShadow =
        afterFocus.boxShadow !== 'none' &&
        afterFocus.boxShadow !== beforeFocus.boxShadow;
    const hasBorderChange = afterFocus.borderColor !== beforeFocus.borderColor;

    if (!hasOutline && !hasShadow && !hasBorderChange) {
        issue(label, 'focused field has no visible focus indicator');
    }

    return {
        fields: visibleFields.length,
        dialog: layout.rect,
        close: closeRect,
    };
}

for (const viewport of viewports) {
    const context = await browser.newContext({
        viewport: { width: viewport.width, height: viewport.height },
        locale: 'ar-LY',
        timezoneId: 'Africa/Tripoli',
        reducedMotion: 'reduce',
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

    try {
        const clientRoute = await resolveClientRoute(page);
        const cases = [
            ...dialogCases,
            {
                label: 'client contact',
                route: clientRoute,
                opener: { role: 'button', name: 'إضافة جهة اتصال' },
            },
        ];

        for (const testCase of cases) {
            const label = `${viewport.width}px ${testCase.label}`;

            try {
                const dialog = await openDialog(page, testCase);
                const audit = await auditDialog(page, dialog, viewport, label);
                results.push({
                    viewport: viewport.name,
                    case: testCase.label,
                    ...audit,
                });

                await dialog
                    .locator('[data-slot="dialog-close"]')
                    .last()
                    .click();
                await dialog.waitFor({ state: 'hidden' });
            } catch (error) {
                failures.push(
                    `${label}: ${error instanceof Error ? error.message : String(error)}`,
                );
            }
        }

        if (runtimeErrors.length > 0) {
            throw new Error(`console errors: ${runtimeErrors.join(' | ')}`);
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
    console.error(
        JSON.stringify({ passed: false, failures, results }, null, 2),
    );
    process.exit(1);
}

console.log(
    JSON.stringify(
        {
            passed: true,
            viewports: viewports.map(({ width }) => width),
            dialogsPerViewport: results.length / viewports.length,
            audits: results.length,
            assertions: [
                'portal tokens resolved',
                'field border/background/height/font',
                'visible focus indicator',
                'close hitbox',
                'dialog containment',
                'document overflow',
                'console errors',
            ],
        },
        null,
        2,
    ),
);

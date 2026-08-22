import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

import {
    launchChromium,
    prepareAuthenticationState,
} from './browser-runtime.mjs';

const baseURL = process.env.APP_URL || 'http://127.0.0.1:8000';
const artifacts = path.resolve('storage', 'app', 'browser-tests');
const routes = [
    '/dashboard',
    '/projects',
    '/tasks',
    '/tasks?view=kanban',
    '/clients',
    '/team',
    '/sales',
    '/data-center',
    '/settings',
    '/settings/notifications',
];
const viewports = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'tablet', width: 1024, height: 900 },
    { name: 'compact', width: 768, height: 900 },
    { name: 'zoom-200-equivalent', width: 720, height: 900 },
    { name: 'zoom-400-equivalent', width: 512, height: 760 },
];

await mkdir(artifacts, { recursive: true });

const browser = await launchChromium();
const failures = [];
const storageState = await prepareAuthenticationState(browser, baseURL);

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
        await page.goto(`${baseURL}/dashboard`, { waitUntil: 'networkidle' });
        await page.evaluate(() => {
            if (document.activeElement instanceof HTMLElement) {
                document.activeElement.blur();
            }
        });
        await page.keyboard.press('Tab');
        const firstTabTarget = await page.evaluate(() => ({
            text: document.activeElement?.textContent?.trim() ?? '',
            href:
                document.activeElement instanceof HTMLAnchorElement
                    ? document.activeElement.getAttribute('href')
                    : null,
        }));

        if (firstTabTarget.href !== '#main-content') {
            throw new Error(
                `skip link is not the first tab target: ${JSON.stringify(firstTabTarget)}`,
            );
        }

        await page.keyboard.press('Enter');
        const skippedToMain = await page.evaluate(
            () => document.activeElement?.id === 'main-content',
        );

        if (!skippedToMain) {
            throw new Error('skip link did not focus the main content');
        }

        for (const route of routes) {
            await page.goto(`${baseURL}${route}`, { waitUntil: 'networkidle' });
            await page.locator('main h1').first().waitFor();

            const audit = await page.evaluate(() => {
                const visible = (element) => {
                    const style = window.getComputedStyle(element);
                    const rect = element.getBoundingClientRect();

                    return (
                        style.display !== 'none' &&
                        style.visibility !== 'hidden' &&
                        rect.width > 0 &&
                        rect.height > 0
                    );
                };
                const labelledText = (element) => {
                    const labelledBy = element.getAttribute('aria-labelledby');

                    if (!labelledBy) {
                        return '';
                    }

                    return labelledBy
                        .split(/\s+/)
                        .map(
                            (id) =>
                                document.getElementById(id)?.textContent ?? '',
                        )
                        .join(' ')
                        .trim();
                };
                const accessibleName = (element) => {
                    const labels =
                        'labels' in element && element.labels
                            ? [...element.labels]
                                  .map((label) => label.textContent ?? '')
                                  .join(' ')
                            : '';

                    return (
                        element.getAttribute('aria-label') ||
                        labelledText(element) ||
                        labels ||
                        element.getAttribute('alt') ||
                        element.getAttribute('title') ||
                        element.getAttribute('placeholder') ||
                        element.textContent ||
                        ''
                    ).trim();
                };
                const controls = [
                    ...document.querySelectorAll(
                        'button, a[href], input:not([type="hidden"]), select, textarea, [role="button"]',
                    ),
                ].filter(visible);
                const unnamed = controls
                    .filter((element) => accessibleName(element) === '')
                    .map(
                        (element) =>
                            `${element.tagName.toLowerCase()}#${element.id || '-'} .${element.className || '-'}`,
                    );
                const ids = [...document.querySelectorAll('[id]')].map(
                    (element) => element.id,
                );
                const duplicateIds = [
                    ...new Set(
                        ids.filter((id, index) => ids.indexOf(id) !== index),
                    ),
                ];
                const tablesWithoutNames = [
                    ...document.querySelectorAll('table'),
                ]
                    .filter(visible)
                    .filter(
                        (table) =>
                            !table.querySelector('caption') &&
                            !table.getAttribute('aria-label') &&
                            !table.getAttribute('aria-labelledby'),
                    ).length;

                return {
                    direction: document.documentElement.dir,
                    language: document.documentElement.lang,
                    mainCount: document.querySelectorAll('main').length,
                    visibleH1Count: [...document.querySelectorAll('h1')].filter(
                        visible,
                    ).length,
                    overflow:
                        document.documentElement.scrollWidth -
                        document.documentElement.clientWidth,
                    duplicateIds,
                    unnamed,
                    imagesWithoutAlt:
                        document.querySelectorAll('img:not([alt])').length,
                    tablesWithoutNames,
                };
            });

            if (audit.direction !== 'rtl' || !audit.language.startsWith('ar')) {
                throw new Error(
                    `${route}: language contract ${audit.language}/${audit.direction}`,
                );
            }

            if (audit.mainCount !== 1 || audit.visibleH1Count !== 1) {
                throw new Error(
                    `${route}: landmarks main=${audit.mainCount}, h1=${audit.visibleH1Count}`,
                );
            }

            if (audit.overflow > 1) {
                throw new Error(
                    `${route}: document overflow ${audit.overflow}px`,
                );
            }

            if (audit.duplicateIds.length > 0) {
                throw new Error(
                    `${route}: duplicate IDs ${audit.duplicateIds.join(', ')}`,
                );
            }

            if (audit.unnamed.length > 0) {
                throw new Error(
                    `${route}: unnamed controls ${audit.unnamed.join(' | ')}`,
                );
            }

            if (audit.imagesWithoutAlt > 0 || audit.tablesWithoutNames > 0) {
                throw new Error(
                    `${route}: images without alt=${audit.imagesWithoutAlt}, tables without names=${audit.tablesWithoutNames}`,
                );
            }

            if (route === '/settings') {
                await page
                    .getByRole('button', { name: /حالات سير العمل/ })
                    .click();
                const workflowTabs = page.getByRole('tablist', {
                    name: 'نوع سير العمل',
                });
                const selectedTab = workflowTabs.locator(
                    '[role="tab"][aria-selected="true"]',
                );
                await selectedTab.focus();
                const before = await selectedTab.textContent();
                await page.keyboard.press('ArrowLeft');
                const after = await workflowTabs
                    .locator('[role="tab"][aria-selected="true"]')
                    .textContent();

                if (!before || !after || before === after) {
                    throw new Error(
                        '/settings: RTL ArrowLeft did not activate the next workflow tab',
                    );
                }
            }
        }

        if (runtimeErrors.length > 0) {
            throw new Error(`console errors: ${runtimeErrors.join(' | ')}`);
        }

        await page.screenshot({
            path: path.join(artifacts, `accessibility-${viewport.name}.png`),
            fullPage: true,
        });
    } catch (error) {
        failures.push(
            `${viewport.name}: ${error instanceof Error ? error.message : String(error)}`,
        );
        await page.screenshot({
            path: path.join(
                artifacts,
                `accessibility-failure-${viewport.name}.png`,
            ),
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

console.log(
    JSON.stringify(
        {
            passed: true,
            routes: routes.length,
            viewports: viewports.length,
            audits: routes.length * viewports.length,
            artifacts,
        },
        null,
        2,
    ),
);

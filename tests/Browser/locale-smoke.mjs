import process from 'node:process';

import {
    launchChromium,
    prepareAuthenticationState,
} from './browser-runtime.mjs';

const baseURL = process.env.APP_URL || 'http://127.0.0.1:8000';
const configuredApplicationName = process.env.BROWSER_APP_NAME;
const routes = [
    { path: '/dashboard', heading: 'Dashboard', title: 'Dashboard' },
    { path: '/projects', heading: 'Projects', title: 'Projects' },
    { path: '/tasks', heading: 'All tasks', title: 'Tasks' },
    {
        path: '/clients',
        heading: 'Clients and contacts',
        title: 'Clients',
    },
    { path: '/team', heading: 'Team members', title: 'Team' },
    {
        path: '/sales',
        heading: 'Invoice templates',
        title: 'Invoice templates',
    },
    { path: '/data-center', heading: 'Data center', title: 'Data center' },
    {
        path: '/settings',
        heading: 'System settings',
        title: 'System settings',
    },
];
const viewports = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 360, height: 800 },
];

function messageOf(error) {
    return error instanceof Error ? error.message : String(error);
}

function assertResponseStatus(response, label) {
    if (!response || response.status() !== 200) {
        throw new Error(
            `${label}: expected HTTP 200, got ${response?.status()}`,
        );
    }
}

function collectRuntimeErrors(page, errors) {
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
}

async function waitForLanguage(page, language, direction) {
    await page
        .locator(`html[lang="${language}"][dir="${direction}"]`)
        .waitFor({ state: 'attached' });
}

async function settleClientRuntime(page) {
    await page.evaluate(
        () =>
            new Promise((resolve) =>
                window.requestAnimationFrame(() =>
                    window.requestAnimationFrame(resolve),
                ),
            ),
    );
}

async function assertLanguageSwitcherInViewport(page, label) {
    const result = await page.evaluate(() => {
        const viewportWidth = document.documentElement.clientWidth;
        const viewportHeight = document.documentElement.clientHeight;

        return [...document.querySelectorAll('.cloudtech-language-switcher')]
            .map((element) => {
                const rect = element.getBoundingClientRect();
                const style = window.getComputedStyle(element);

                return {
                    accessibleName:
                        element.getAttribute('aria-label')?.trim() ?? '',
                    inViewport:
                        style.display !== 'none' &&
                        style.visibility !== 'hidden' &&
                        Number(style.opacity) > 0 &&
                        rect.width > 0 &&
                        rect.height > 0 &&
                        rect.left >= -1 &&
                        rect.top >= -1 &&
                        rect.right <= viewportWidth + 1 &&
                        rect.bottom <= viewportHeight + 1,
                };
            })
            .find((candidate) => candidate.inViewport);
    });

    if (!result?.accessibleName) {
        throw new Error(`${label}: visible language switcher was not found`);
    }
}

async function findRawTranslationKeys(page) {
    return await page.evaluate(() => {
        const rawKey =
            /\b(?:account|auth|brand|clients|dashboard|data-center|dataCenter|language|nav|projects|sales|settings|shell|tasks|team|welcome)\.[A-Za-z][A-Za-z0-9_.-]*\b/g;
        const values = [document.body.innerText];

        document
            .querySelectorAll(
                '[aria-label], [aria-description], [placeholder], [title], [alt]',
            )
            .forEach((element) => {
                for (const attribute of [
                    'aria-label',
                    'aria-description',
                    'placeholder',
                    'title',
                    'alt',
                ]) {
                    const value = element.getAttribute(attribute);

                    if (value) {
                        values.push(value);
                    }
                }
            });

        return [
            ...new Set(values.flatMap((value) => value.match(rawKey) ?? [])),
        ];
    });
}

async function assertNoRawTranslationKeys(page, label) {
    const rawKeys = await findRawTranslationKeys(page);

    if (rawKeys.length > 0) {
        throw new Error(`${label}: raw translation keys ${rawKeys.join(', ')}`);
    }
}

async function assertEnglishNumberFormatting(page, label) {
    const arabicIndicDigits = await page.evaluate(() => {
        const matches = document.body.innerText.match(/[٠-٩]/gu) ?? [];

        return [...new Set(matches)];
    });

    if (arabicIndicDigits.length > 0) {
        throw new Error(
            `${label}: Arabic-Indic digits remained after switching to English (${arabicIndicDigits.join(', ')})`,
        );
    }
}

async function assertNoDocumentOverflow(page, label) {
    const overflow = await page.evaluate(() => {
        const documentWidth = Math.max(
            document.documentElement.scrollWidth,
            document.body?.scrollWidth ?? 0,
        );

        return documentWidth - document.documentElement.clientWidth;
    });

    if (overflow > 1) {
        throw new Error(`${label}: document overflow ${overflow}px`);
    }
}

async function guestLocaleJourney(browser) {
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        locale: 'ar-LY',
        timezoneId: 'Africa/Tripoli',
    });
    const page = await context.newPage();
    const runtimeErrors = [];
    collectRuntimeErrors(page, runtimeErrors);

    try {
        const response = await page.goto(`${baseURL}/login`, {
            waitUntil: 'networkidle',
        });
        assertResponseStatus(response, 'guest /login');
        await waitForLanguage(page, 'ar', 'rtl');
        await page
            .getByRole('heading', {
                level: 1,
                name: 'تسجيل الدخول إلى حسابك',
                exact: true,
            })
            .waitFor();
        await assertLanguageSwitcherInViewport(page, 'guest Arabic /login');

        const englishSwitcher = page.getByRole('button', {
            name: 'التبديل إلى English',
            exact: true,
        });
        await englishSwitcher.waitFor({ state: 'visible' });
        await englishSwitcher.click();
        await waitForLanguage(page, 'en', 'ltr');
        await page
            .getByRole('heading', {
                level: 1,
                name: 'Sign in to your account',
                exact: true,
            })
            .waitFor();
        await page.getByLabel('Email address', { exact: true }).waitFor();
        await page.getByLabel('Password', { exact: true }).waitFor();
        await page
            .getByRole('button', { name: 'Sign in', exact: true })
            .waitFor();
        await assertNoRawTranslationKeys(page, 'guest English /login');

        const englishReload = await page.reload({ waitUntil: 'networkidle' });
        assertResponseStatus(englishReload, 'guest English /login reload');
        await waitForLanguage(page, 'en', 'ltr');
        await page
            .getByRole('heading', {
                level: 1,
                name: 'Sign in to your account',
                exact: true,
            })
            .waitFor();
        await assertLanguageSwitcherInViewport(
            page,
            'guest English /login reload',
        );
        await assertNoRawTranslationKeys(page, 'guest English /login reload');

        const arabicSwitcher = page.getByRole('button', {
            name: 'Switch to العربية',
            exact: true,
        });
        await arabicSwitcher.waitFor({ state: 'visible' });
        await arabicSwitcher.click();
        await waitForLanguage(page, 'ar', 'rtl');
        await page
            .getByRole('heading', {
                level: 1,
                name: 'تسجيل الدخول إلى حسابك',
                exact: true,
            })
            .waitFor();

        const arabicReload = await page.reload({ waitUntil: 'networkidle' });
        assertResponseStatus(arabicReload, 'guest Arabic /login reload');
        await waitForLanguage(page, 'ar', 'rtl');
        await page
            .getByRole('heading', {
                level: 1,
                name: 'تسجيل الدخول إلى حسابك',
                exact: true,
            })
            .waitFor();
        await assertLanguageSwitcherInViewport(
            page,
            'guest Arabic /login reload',
        );
        await settleClientRuntime(page);

        if (runtimeErrors.length > 0) {
            throw new Error(runtimeErrors.join(' | '));
        }
    } finally {
        await context.close();
    }
}

async function prepareEnglishAdminState(browser) {
    const authenticatedState = await prepareAuthenticationState(
        browser,
        baseURL,
    );
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        locale: 'en-US',
        timezoneId: 'Africa/Tripoli',
        storageState: authenticatedState,
    });
    const page = await context.newPage();
    const runtimeErrors = [];
    collectRuntimeErrors(page, runtimeErrors);

    try {
        const response = await page.goto(`${baseURL}/dashboard`, {
            waitUntil: 'networkidle',
        });
        assertResponseStatus(response, 'authenticated /dashboard setup');
        await page.locator('main h1').first().waitFor();

        if ((await page.locator('html').getAttribute('lang')) !== 'en') {
            const switcher = page.getByRole('button', {
                name: 'التبديل إلى English',
                exact: true,
            });
            await switcher.waitFor({ state: 'visible' });
            await switcher.click();
        }

        await waitForLanguage(page, 'en', 'ltr');
        await page
            .getByRole('heading', {
                level: 1,
                name: 'Dashboard',
                exact: true,
            })
            .waitFor();
        await assertEnglishNumberFormatting(
            page,
            'authenticated live English switch',
        );

        const reload = await page.reload({ waitUntil: 'networkidle' });
        assertResponseStatus(reload, 'authenticated English state reload');
        await waitForLanguage(page, 'en', 'ltr');
        await page
            .getByRole('heading', {
                level: 1,
                name: 'Dashboard',
                exact: true,
            })
            .waitFor();
        await settleClientRuntime(page);

        if (runtimeErrors.length > 0) {
            throw new Error(runtimeErrors.join(' | '));
        }

        const englishState = await context.storageState();
        const dashboardTitle = await page.title();
        const dashboardTitlePrefix = 'Dashboard - ';
        const applicationName =
            configuredApplicationName ??
            (dashboardTitle.startsWith(dashboardTitlePrefix)
                ? dashboardTitle.slice(dashboardTitlePrefix.length)
                : '');

        if (!applicationName) {
            throw new Error(
                `Could not resolve the application name from document title "${dashboardTitle}"`,
            );
        }

        if (
            !englishState.cookies.some(
                (cookie) => cookie.name === 'project_desk_locale',
            )
        ) {
            throw new Error(
                'English locale cookie is missing from storage state',
            );
        }

        return { applicationName, storageState: englishState };
    } finally {
        await context.close();
    }
}

async function auditAuthenticatedEnglishRoutes(
    browser,
    storageState,
    applicationName,
) {
    const failures = [];

    for (const viewport of viewports) {
        const context = await browser.newContext({
            viewport: { width: viewport.width, height: viewport.height },
            locale: 'en-US',
            timezoneId: 'Africa/Tripoli',
            reducedMotion: 'reduce',
            storageState,
        });
        const page = await context.newPage();
        const runtimeErrors = [];
        page.setDefaultTimeout(12_000);
        collectRuntimeErrors(page, runtimeErrors);

        try {
            for (const route of routes) {
                const label = `${viewport.width}px ${route.path}`;
                runtimeErrors.length = 0;

                try {
                    const response = await page.goto(
                        `${baseURL}${route.path}`,
                        { waitUntil: 'networkidle' },
                    );
                    assertResponseStatus(response, label);
                    await waitForLanguage(page, 'en', 'ltr');
                    await page
                        .getByRole('heading', {
                            level: 1,
                            name: route.heading,
                            exact: true,
                        })
                        .waitFor();
                    await settleClientRuntime(page);

                    const title = await page.title();

                    if (title !== `${route.title} - ${applicationName}`) {
                        throw new Error(
                            `${label}: expected document title "${route.title} - ${applicationName}", got "${title}"`,
                        );
                    }

                    const root = await page
                        .locator('html')
                        .evaluate((html) => ({
                            direction: html.getAttribute('dir'),
                            language: html.getAttribute('lang'),
                        }));

                    if (root.language !== 'en' || root.direction !== 'ltr') {
                        throw new Error(
                            `${label}: language contract ${root.language}/${root.direction}`,
                        );
                    }

                    if (viewport.width >= 768) {
                        const sidebarSide = await page
                            .locator('[data-slot="sidebar"][data-side]')
                            .first()
                            .getAttribute('data-side');

                        if (sidebarSide !== 'left') {
                            throw new Error(
                                `${label}: expected the English sidebar on the left, got ${sidebarSide}`,
                            );
                        }
                    }

                    await assertNoDocumentOverflow(page, label);
                    await assertLanguageSwitcherInViewport(page, label);
                    await assertNoRawTranslationKeys(page, label);
                    await assertEnglishNumberFormatting(page, label);

                    if (runtimeErrors.length > 0) {
                        throw new Error(runtimeErrors.join(' | '));
                    }
                } catch (error) {
                    failures.push(`${label}: ${messageOf(error)}`);
                }
            }
        } finally {
            await context.close();
        }
    }

    return failures;
}

const browser = await launchChromium();
const failures = [];

try {
    try {
        await guestLocaleJourney(browser);
    } catch (error) {
        failures.push(`guest locale journey: ${messageOf(error)}`);
    }

    let englishAdminState;

    try {
        englishAdminState = await prepareEnglishAdminState(browser);
    } catch (error) {
        failures.push(`English admin storage state: ${messageOf(error)}`);
    }

    if (englishAdminState) {
        failures.push(
            ...(await auditAuthenticatedEnglishRoutes(
                browser,
                englishAdminState.storageState,
                englishAdminState.applicationName,
            )),
        );
    }
} finally {
    await browser.close();
}

if (failures.length > 0) {
    console.error(JSON.stringify({ passed: false, failures }, null, 2));
    process.exit(1);
}

console.log(
    JSON.stringify(
        {
            passed: true,
            guestLocalePersistence: true,
            authenticatedRoutes: routes.length,
            viewports: viewports.map(({ width }) => width),
            audits: routes.length * viewports.length,
        },
        null,
        2,
    ),
);

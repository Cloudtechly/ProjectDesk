import { existsSync, readFileSync } from 'node:fs';
import process from 'node:process';

import { chromium } from 'playwright';

const windowsChrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';

export function launchChromium() {
    const configuredPath = process.env.CHROME_PATH?.trim();
    const executablePath =
        configuredPath ||
        (process.platform === 'win32' && existsSync(windowsChrome)
            ? windowsChrome
            : undefined);

    return chromium.launch({
        headless: true,
        ...(executablePath ? { executablePath } : {}),
    });
}

export async function prepareAuthenticationState(browser, baseURL) {
    const sharedState = process.env.BROWSER_STORAGE_STATE?.trim();

    if (sharedState && existsSync(sharedState)) {
        return JSON.parse(readFileSync(sharedState, 'utf8'));
    }

    const context = await browser.newContext({
        locale: 'ar-LY',
        timezoneId: 'Africa/Tripoli',
    });
    const page = await context.newPage();

    try {
        await page.goto(`${baseURL}/login`, { waitUntil: 'networkidle' });
        await page
            .locator('input[name="email"]')
            .fill(process.env.BROWSER_ADMIN_EMAIL || 'admin@projectdesk.local');
        await page
            .locator('input[name="password"]')
            .fill(process.env.BROWSER_ADMIN_PASSWORD || 'password');
        await page.locator('form button[type="submit"]').click();
        await page.waitForURL('**/dashboard');

        return await context.storageState();
    } finally {
        await context.close();
    }
}

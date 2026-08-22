import { spawn } from 'node:child_process';
import { mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import {
    launchChromium,
    prepareAuthenticationState,
} from './browser-runtime.mjs';

const baseURL = process.env.APP_URL || 'http://127.0.0.1:8000';
const scripts = [
    'smoke.mjs',
    'critical-journey.mjs',
    'sales-smoke.mjs',
    'invoice-template-contract.mjs',
    'documents-data-settings-smoke.mjs',
    'governance-smoke.mjs',
    'notifications-smoke.mjs',
    'notification-preferences-smoke.mjs',
    'accessibility-responsive-smoke.mjs',
    'ui-task-regressions.mjs',
    'unsaved-dialogs-smoke.mjs',
    'locale-smoke.mjs',
];

async function waitForApplication() {
    const deadline = Date.now() + 30_000;

    while (Date.now() < deadline) {
        try {
            const response = await fetch(`${baseURL}/login`, {
                redirect: 'manual',
            });

            if (response.status >= 200 && response.status < 500) {
                return;
            }
        } catch {
            // The application process may still be booting.
        }

        await new Promise((resolve) => setTimeout(resolve, 500));
    }

    throw new Error(`Application did not become ready at ${baseURL}`);
}

function runScript(script, storageStatePath) {
    return new Promise((resolve, reject) => {
        const child = spawn(
            process.execPath,
            [fileURLToPath(new URL(script, import.meta.url))],
            {
                stdio: 'inherit',
                env: {
                    ...process.env,
                    APP_URL: baseURL,
                    BROWSER_STORAGE_STATE: storageStatePath,
                },
            },
        );

        child.once('error', reject);
        child.once('exit', (code, signal) => {
            if (code === 0) {
                resolve();

                return;
            }

            reject(
                new Error(
                    `${script} failed${signal ? ` with signal ${signal}` : ` with exit code ${code}`}`,
                ),
            );
        });
    });
}

await waitForApplication();

const artifacts = path.resolve('storage', 'app', 'browser-tests');
const storageStatePath = path.join(
    artifacts,
    `.auth-state-${process.pid}.json`,
);
await mkdir(artifacts, { recursive: true });

const browser = await launchChromium();

try {
    const storageState = await prepareAuthenticationState(browser, baseURL);
    await writeFile(storageStatePath, JSON.stringify(storageState), {
        encoding: 'utf8',
        mode: 0o600,
    });
} finally {
    await browser.close();
}

try {
    for (const script of scripts) {
        console.log(`\n=== Browser check: ${script} ===`);
        await runScript(script, storageStatePath);
    }

    console.log(`\nAll ${scripts.length} browser workflows passed.`);
} finally {
    await rm(storageStatePath, { force: true });
}

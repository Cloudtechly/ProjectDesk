import { spawn } from 'node:child_process';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const runs = Number.parseInt(process.env.BROWSER_RELEASE_RUNS || '3', 10);

if (!Number.isInteger(runs) || runs < 1 || runs > 5) {
    throw new Error('BROWSER_RELEASE_RUNS must be an integer between 1 and 5.');
}

function runSuite(attempt) {
    return new Promise((resolve, reject) => {
        console.log(`\n=== Release browser suite ${attempt}/${runs} ===`);
        const child = spawn(
            process.execPath,
            [fileURLToPath(new URL('run-all.mjs', import.meta.url))],
            {
                stdio: 'inherit',
                env: process.env,
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
                    `Browser release suite ${attempt} failed${
                        signal
                            ? ` with signal ${signal}`
                            : ` with exit code ${code}`
                    }`,
                ),
            );
        });
    });
}

for (let attempt = 1; attempt <= runs; attempt += 1) {
    await runSuite(attempt);
}

console.log(`\nAll ${runs} release browser suites passed.`);

import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const projectRoot = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '..',
    '..',
);
const resourcesRoot = path.join(projectRoot, 'resources', 'js');
const i18nRoot = path.join(resourcesRoot, 'i18n');
const messagesRoot = path.join(i18nRoot, 'messages');
const compatibilityBridge = path.join(i18nRoot, 'compatibility-bridge.ts');
const ts = require(
    path.join(
        projectRoot,
        'node_modules',
        'typescript',
        'lib',
        'typescript.js',
    ),
);

const ARABIC_TEXT =
    /[\u0600-\u06ff\u0750-\u077f\u08a0-\u08ff\ufb50-\ufdff\ufe70-\ufeff]/u;

// Every exception must name the exact runtime value and explain why it is not UI
// copy. Keep this list deliberately small: entries are reported in the summary.
const STATIC_ALLOWLIST = new Map([]);

// Dynamic values normally require a full, anchored catalog pattern. This list is
// reserved for templates that only construct technical/user-content values.
const TEMPLATE_ALLOWLIST = new Map([]);

function normalizePath(filePath) {
    return path.relative(projectRoot, filePath).split(path.sep).join('/');
}

function listFiles(directory, predicate) {
    const files = [];

    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        const entryPath = path.join(directory, entry.name);

        if (entry.isDirectory()) {
            files.push(...listFiles(entryPath, predicate));
        } else if (predicate(entryPath)) {
            files.push(entryPath);
        }
    }

    return files.sort((left, right) => left.localeCompare(right));
}

function assertAllowlist(name, allowlist) {
    for (const [value, reason] of allowlist) {
        if (!ARABIC_TEXT.test(value)) {
            throw new Error(
                `${name}: non-Arabic allowlist value ${JSON.stringify(value)}`,
            );
        }

        if (typeof reason !== 'string' || reason.trim().length < 12) {
            throw new Error(
                `${name}: ${JSON.stringify(value)} needs a specific rationale`,
            );
        }
    }
}

function diagnosticText(diagnostic) {
    return ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n');
}

function transpile(source, fileName, moduleKind) {
    const result = ts.transpileModule(source, {
        compilerOptions: {
            esModuleInterop: true,
            jsx: ts.JsxEmit.ReactJSX,
            module: moduleKind,
            target: ts.ScriptTarget.ES2022,
        },
        fileName,
        reportDiagnostics: true,
    });
    const errors = (result.diagnostics ?? []).filter(
        (diagnostic) => diagnostic.category === ts.DiagnosticCategory.Error,
    );

    if (errors.length > 0) {
        throw new Error(
            `${normalizePath(fileName)} could not be parsed:\n${errors
                .map(diagnosticText)
                .join('\n')}`,
        );
    }

    return result.outputText;
}

function addOccurrence(collection, value, location, kind) {
    if (!ARABIC_TEXT.test(value)) {
        return;
    }

    const occurrences = collection.get(value) ?? [];
    occurrences.push({ kind, location });
    collection.set(value, occurrences);
}

function templateSample(node) {
    let sample = node.head.text;

    node.templateSpans.forEach((span, index) => {
        sample += `catalog-value-${index + 1}`;
        sample += span.literal.text;
    });

    return sample;
}

function inventorySourceFiles() {
    // Catalog definitions are inputs to the audit, not untranslated UI source.
    // The bridge is excluded for the same reason: its Arabic literals define the
    // small foundation catalog. All other resources/js modules remain in scope.
    const sourceFiles = listFiles(
        resourcesRoot,
        (filePath) =>
            /\.[cm]?[jt]sx?$/u.test(filePath) && !filePath.endsWith('.d.ts'),
    ).filter(
        (filePath) =>
            !filePath.startsWith(`${messagesRoot}${path.sep}`) &&
            filePath !== compatibilityBridge,
    );
    const staticValues = new Map();
    const templates = new Map();

    for (const filePath of sourceFiles) {
        const source = fs.readFileSync(filePath, 'utf8');
        const emitted = transpile(source, filePath, ts.ModuleKind.ESNext);
        const emittedFile = ts.createSourceFile(
            filePath,
            emitted,
            ts.ScriptTarget.ES2022,
            true,
            ts.ScriptKind.JS,
        );
        const relativePath = normalizePath(filePath);

        function visit(node) {
            const position = emittedFile.getLineAndCharacterOfPosition(
                node.getStart(emittedFile),
            );
            const location = `${relativePath}:${position.line + 1}`;

            if (
                ts.isStringLiteral(node) ||
                ts.isNoSubstitutionTemplateLiteral(node)
            ) {
                addOccurrence(staticValues, node.text, location, 'static');
            } else if (ts.isTemplateExpression(node)) {
                addOccurrence(
                    templates,
                    templateSample(node),
                    location,
                    'template',
                );
            }

            ts.forEachChild(node, visit);
        }

        visit(emittedFile);
    }

    return { sourceFiles, staticValues, templates };
}

function evaluateMessageModule(filePath) {
    const source = fs.readFileSync(filePath, 'utf8');
    const emitted = transpile(source, filePath, ts.ModuleKind.CommonJS);
    const module = { exports: {} };
    const localRequire = (specifier) => {
        if (specifier === '@/i18n/translator') {
            return { defineMessages: (messages) => messages };
        }

        throw new Error(
            `${normalizePath(filePath)} imports unsupported module ${specifier}`,
        );
    };
    const execute = new Function('exports', 'module', 'require', emitted);
    execute(module.exports, module, localRequire);

    return module.exports;
}

function isStringRecord(value) {
    return (
        value !== null &&
        typeof value === 'object' &&
        !Array.isArray(value) &&
        Object.values(value).every((entry) => typeof entry === 'string')
    );
}

function addExactMapping(exactMappings, source, target, origin) {
    if (!ARABIC_TEXT.test(source)) {
        return;
    }

    const mappings = exactMappings.get(source) ?? [];
    mappings.push({ origin, target });
    exactMappings.set(source, mappings);
}

function loadCatalog() {
    const messageFiles = listFiles(messagesRoot, (filePath) =>
        filePath.endsWith('.ts'),
    );
    const exactMappings = new Map();
    const patterns = [];

    for (const filePath of messageFiles) {
        const exports = evaluateMessageModule(filePath);
        const relativePath = normalizePath(filePath);

        for (const [exportName, value] of Object.entries(exports)) {
            if (exportName.endsWith('English') && isStringRecord(value)) {
                for (const [source, target] of Object.entries(value)) {
                    addExactMapping(
                        exactMappings,
                        source,
                        target,
                        `${relativePath}#${exportName}`,
                    );
                }

                continue;
            }

            if (exportName.endsWith('Patterns') && Array.isArray(value)) {
                value.forEach((entry, index) => {
                    if (
                        entry === null ||
                        typeof entry !== 'object' ||
                        !(entry.source instanceof RegExp) ||
                        typeof entry.target !== 'string'
                    ) {
                        throw new Error(
                            `${relativePath}#${exportName}[${index}] is not a RegExp/target pair`,
                        );
                    }

                    patterns.push({
                        origin: `${relativePath}#${exportName}[${index}]`,
                        source: entry.source,
                        target: entry.target,
                    });
                });

                continue;
            }

            if (
                value !== null &&
                typeof value === 'object' &&
                isStringRecord(value.ar) &&
                isStringRecord(value.en)
            ) {
                const arabicKeys = Object.keys(value.ar).sort();
                const englishKeys = Object.keys(value.en).sort();

                if (
                    JSON.stringify(arabicKeys) !== JSON.stringify(englishKeys)
                ) {
                    throw new Error(
                        `${relativePath}#${exportName} has mismatched ar/en keys`,
                    );
                }

                for (const key of arabicKeys) {
                    addExactMapping(
                        exactMappings,
                        value.ar[key],
                        value.en[key],
                        `${relativePath}#${exportName}.${key}`,
                    );
                }
            }
        }
    }

    return { exactMappings, messageFiles, patterns };
}

function patternMatches(pattern, value) {
    pattern.lastIndex = 0;

    return pattern.test(value);
}

function patternOutput(pattern, target, value) {
    pattern.lastIndex = 0;

    return value.replace(pattern, target);
}

function uniqueTargets(mappings) {
    return [...new Set(mappings.map(({ target }) => target))];
}

function sourceLocations(occurrences) {
    return [...new Set(occurrences.map(({ location }) => location))];
}

function serializeMissing(values) {
    return values.map(({ occurrences, value }) => ({
        locations: sourceLocations(occurrences),
        value,
    }));
}

function audit() {
    assertAllowlist('STATIC_ALLOWLIST', STATIC_ALLOWLIST);
    assertAllowlist('TEMPLATE_ALLOWLIST', TEMPLATE_ALLOWLIST);

    const inventory = inventorySourceFiles();
    const catalog = loadCatalog();
    const failures = {
        duplicatePatterns: [],
        exactCollisions: [],
        invalidPatterns: [],
        missingExact: [],
        missingTemplatePatterns: [],
        templatePatternCollisions: [],
    };

    for (const [source, mappings] of catalog.exactMappings) {
        const targets = uniqueTargets(mappings);

        if (targets.length > 1) {
            failures.exactCollisions.push({ source, mappings });
        }
    }

    const patternsBySignature = new Map();

    for (const pattern of catalog.patterns) {
        if (
            !pattern.source.source.startsWith('^') ||
            !pattern.source.source.endsWith('$') ||
            pattern.source.global ||
            pattern.source.sticky
        ) {
            failures.invalidPatterns.push({
                flags: pattern.source.flags,
                origin: pattern.origin,
                source: pattern.source.source,
            });
        }

        const signature = `${pattern.source.source}/${pattern.source.flags}`;
        const definitions = patternsBySignature.get(signature) ?? [];
        definitions.push(pattern);
        patternsBySignature.set(signature, definitions);
    }

    for (const [signature, definitions] of patternsBySignature) {
        if (uniqueTargets(definitions).length > 1) {
            failures.duplicatePatterns.push({ signature, definitions });
        }
    }

    let exactCovered = 0;
    let staticAllowlisted = 0;

    for (const [value, occurrences] of inventory.staticValues) {
        const trimmed = value.trim();

        if (
            catalog.exactMappings.has(value) ||
            (trimmed !== value && catalog.exactMappings.has(trimmed))
        ) {
            exactCovered += 1;
        } else if (STATIC_ALLOWLIST.has(value)) {
            staticAllowlisted += 1;
        } else {
            failures.missingExact.push({ occurrences, value });
        }
    }

    let templateCovered = 0;
    let templateAllowlisted = 0;

    for (const [value, occurrences] of inventory.templates) {
        const core = value.trim();
        const matches = catalog.patterns.filter(({ source }) =>
            patternMatches(source, core),
        );

        if (matches.length === 0) {
            if (TEMPLATE_ALLOWLIST.has(value)) {
                templateAllowlisted += 1;
            } else {
                failures.missingTemplatePatterns.push({ occurrences, value });
            }

            continue;
        }

        templateCovered += 1;
        const outputs = new Map();

        for (const match of matches) {
            const output = patternOutput(match.source, match.target, core);
            const origins = outputs.get(output) ?? [];
            origins.push(match.origin);
            outputs.set(output, origins);
        }

        if (outputs.size > 1) {
            failures.templatePatternCollisions.push({
                matches: [...outputs].map(([output, origins]) => ({
                    origins,
                    output,
                })),
                occurrences,
                value,
            });
        }
    }

    const staticOccurrences = [...inventory.staticValues.values()].reduce(
        (total, occurrences) => total + occurrences.length,
        0,
    );
    const templateOccurrences = [...inventory.templates.values()].reduce(
        (total, occurrences) => total + occurrences.length,
        0,
    );
    const failureCount = Object.values(failures).reduce(
        (total, entries) => total + entries.length,
        0,
    );
    const summary = {
        passed: failureCount === 0,
        sourceFiles: inventory.sourceFiles.length,
        messageFiles: catalog.messageFiles.length,
        catalog: {
            exactArabicKeys: catalog.exactMappings.size,
            patterns: catalog.patterns.length,
        },
        static: {
            occurrences: staticOccurrences,
            unique: inventory.staticValues.size,
            exactCovered,
            allowlisted: staticAllowlisted,
            missing: failures.missingExact.length,
            coveragePercent:
                inventory.staticValues.size === 0
                    ? 100
                    : Number(
                          (
                              ((exactCovered + staticAllowlisted) /
                                  inventory.staticValues.size) *
                              100
                          ).toFixed(2),
                      ),
        },
        templates: {
            occurrences: templateOccurrences,
            unique: inventory.templates.size,
            patternCovered: templateCovered,
            allowlisted: templateAllowlisted,
            missing: failures.missingTemplatePatterns.length,
            coveragePercent:
                inventory.templates.size === 0
                    ? 100
                    : Number(
                          (
                              ((templateCovered + templateAllowlisted) /
                                  inventory.templates.size) *
                              100
                          ).toFixed(2),
                      ),
        },
        collisions: {
            exact: failures.exactCollisions.length,
            duplicatePatterns: failures.duplicatePatterns.length,
            templatePatterns: failures.templatePatternCollisions.length,
        },
        invalidPatterns: failures.invalidPatterns.length,
    };

    return { failures, summary };
}

const { failures, summary } = audit();

console.log(JSON.stringify(summary, null, 2));

if (!summary.passed) {
    console.error(
        JSON.stringify(
            {
                failures: {
                    ...failures,
                    missingExact: serializeMissing(failures.missingExact),
                    missingTemplatePatterns: serializeMissing(
                        failures.missingTemplatePatterns,
                    ),
                },
            },
            null,
            2,
        ),
    );
    process.exit(1);
}

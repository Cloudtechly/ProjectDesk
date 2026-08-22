import {
    defineCompatibilityCatalog,
    mergeCompatibilityCatalogs,
    registerCompatibilityCatalog,
} from '@/i18n/catalog';
import type {
    CompatibilityCatalog,
    CompatibilityPattern,
} from '@/i18n/catalog';

type CompatibilityMessageModule = Record<string, unknown>;

const messageLoaders = import.meta.glob<CompatibilityMessageModule>(
    './messages/*.ts',
);
let loadingCatalogs: Promise<void> | null = null;

function isEnglishMessages(value: unknown): value is Record<string, string> {
    return (
        typeof value === 'object' &&
        value !== null &&
        !Array.isArray(value) &&
        Object.values(value).every((message) => typeof message === 'string')
    );
}

function isCompatibilityPattern(value: unknown): value is CompatibilityPattern {
    return (
        typeof value === 'object' &&
        value !== null &&
        'source' in value &&
        value.source instanceof RegExp &&
        'target' in value &&
        typeof value.target === 'string'
    );
}

export function catalogFromMessageModule(
    id: string,
    messageModule: CompatibilityMessageModule,
): CompatibilityCatalog | null {
    const exact: Record<string, string> = {};
    const patterns: CompatibilityPattern[] = [];

    Object.entries(messageModule).forEach(([name, value]) => {
        if (name.endsWith('English') && isEnglishMessages(value)) {
            Object.assign(exact, value);
        }

        if (name.endsWith('Patterns') && Array.isArray(value)) {
            patterns.push(...value.filter(isCompatibilityPattern));
        }
    });

    if (Object.keys(exact).length === 0 && patterns.length === 0) {
        return null;
    }

    return defineCompatibilityCatalog({ id, exact, patterns });
}

export function loadBundledCompatibilityCatalogs(): Promise<void> {
    if (loadingCatalogs) {
        return loadingCatalogs;
    }

    loadingCatalogs = (async () => {
        const entries = Object.entries(messageLoaders).sort(([left], [right]) =>
            left.localeCompare(right),
        );
        const loadedModules = await Promise.all(
            entries.map(async ([path, load]) => [path, await load()] as const),
        );

        const catalogs = loadedModules
            .map(([path, messageModule]) =>
                catalogFromMessageModule(
                    `messages:${path
                        .replace(/^.*\//u, '')
                        .replace(/\.ts$/u, '')}`,
                    messageModule,
                ),
            )
            .filter((catalog): catalog is CompatibilityCatalog =>
                Boolean(catalog),
            );

        if (catalogs.length > 0) {
            registerCompatibilityCatalog(
                mergeCompatibilityCatalogs('messages:bundled', ...catalogs),
            );
        }
    })().catch((error: unknown) => {
        loadingCatalogs = null;

        throw error;
    });

    return loadingCatalogs;
}

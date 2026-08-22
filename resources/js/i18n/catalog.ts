export type CompatibilityScope = 'attribute' | 'text';

export type CompatibilityPattern = {
    source: RegExp;
    target: string;
    scope?: CompatibilityScope | 'all';
};

export type CompatibilityCatalog = {
    id: string;
    exact?: Readonly<Record<string, string>>;
    patterns?: readonly CompatibilityPattern[];
};

type ResolvedCatalog = {
    exact: Map<string, string>;
    patterns: CompatibilityPattern[];
};

const catalogs = new Map<string, CompatibilityCatalog>();
const listeners = new Set<() => void>();
let resolvedCatalog: ResolvedCatalog | null = null;

function validatePattern(pattern: CompatibilityPattern, catalogId: string) {
    const source = pattern.source.source;

    if (!source.startsWith('^') || !source.endsWith('$')) {
        throw new Error(
            `Compatibility pattern in ${catalogId} must match the complete value: ${pattern.source}`,
        );
    }

    if (pattern.source.global || pattern.source.sticky) {
        throw new Error(
            `Compatibility pattern in ${catalogId} cannot use stateful flags: ${pattern.source}`,
        );
    }
}

export function defineCompatibilityCatalog(
    catalog: CompatibilityCatalog,
): CompatibilityCatalog {
    catalog.patterns?.forEach((pattern) =>
        validatePattern(pattern, catalog.id),
    );

    return catalog;
}

export function mergeCompatibilityCatalogs(
    id: string,
    ...sources: CompatibilityCatalog[]
): CompatibilityCatalog {
    const exact: Record<string, string> = {};
    const patterns: CompatibilityPattern[] = [];

    sources.forEach((source) => {
        Object.entries(source.exact ?? {}).forEach(([arabic, english]) => {
            if (exact[arabic] && exact[arabic] !== english) {
                if (import.meta.env.DEV) {
                    console.warn(
                        `Conflicting compatibility translation for "${arabic}"; keeping "${exact[arabic]}" from the earlier catalog.`,
                    );
                }

                return;
            }

            exact[arabic] = english;
        });
        patterns.push(...(source.patterns ?? []));
    });

    return defineCompatibilityCatalog({ id, exact, patterns });
}

export function registerCompatibilityCatalog(
    catalog: CompatibilityCatalog,
): () => void {
    const validated = defineCompatibilityCatalog(catalog);
    catalogs.set(validated.id, validated);
    resolvedCatalog = null;
    listeners.forEach((listener) => listener());

    return () => {
        if (catalogs.get(validated.id) !== validated) {
            return;
        }

        catalogs.delete(validated.id);
        resolvedCatalog = null;
        listeners.forEach((listener) => listener());
    };
}

export function subscribeToCompatibilityCatalogs(
    listener: () => void,
): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

function getResolvedCatalog(): ResolvedCatalog {
    if (resolvedCatalog) {
        return resolvedCatalog;
    }

    const exact = new Map<string, string>();
    const patterns: CompatibilityPattern[] = [];

    catalogs.forEach((catalog) => {
        Object.entries(catalog.exact ?? {}).forEach(([arabic, english]) => {
            const existing = exact.get(arabic);

            if (existing && existing !== english) {
                if (import.meta.env.DEV) {
                    console.warn(
                        `Conflicting compatibility translation for "${arabic}"; keeping "${existing}" from the earlier catalog.`,
                    );
                }

                return;
            }

            exact.set(arabic, english);
        });
        patterns.push(...(catalog.patterns ?? []));
    });

    resolvedCatalog = { exact, patterns };

    return resolvedCatalog;
}

function resolvePattern(
    value: string,
    scope: CompatibilityScope,
): string | null {
    const catalog = getResolvedCatalog();

    for (const pattern of catalog.patterns) {
        if (
            pattern.scope &&
            pattern.scope !== 'all' &&
            pattern.scope !== scope
        ) {
            continue;
        }

        pattern.source.lastIndex = 0;

        if (pattern.source.test(value)) {
            pattern.source.lastIndex = 0;

            return value.replace(pattern.source, pattern.target);
        }
    }

    return null;
}

export function resolveCompatibilityValue(
    value: string,
    scope: CompatibilityScope,
): string | null {
    const catalog = getResolvedCatalog();
    const complete = catalog.exact.get(value);

    if (complete !== undefined) {
        return complete;
    }

    const core = value.trim();

    if (!core || core === value) {
        return resolvePattern(core || value, scope);
    }

    const start = value.indexOf(core);
    const leading = value.slice(0, start);
    const trailing = value.slice(start + core.length);
    const exact = catalog.exact.get(core);

    if (exact !== undefined) {
        return `${leading}${exact}${trailing}`;
    }

    const translated = resolvePattern(core, scope);

    return translated === null ? null : `${leading}${translated}${trailing}`;
}

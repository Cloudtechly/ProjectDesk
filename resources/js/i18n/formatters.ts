export type FormattingLocale = 'ar-LY' | 'en-GB';

export interface LocaleNumberFormatter {
    format(value: number | bigint): string;
}

export interface LocaleDateTimeFormatter {
    format(value?: Date | number): string;
}

let activeFormattingLocale: FormattingLocale | null = null;

function normalizeFormattingLocale(locale: string): FormattingLocale {
    const normalized = locale.trim().toLowerCase();

    return normalized === 'en' || normalized.startsWith('en-')
        ? 'en-GB'
        : 'ar-LY';
}

export function setFormattingLocale(locale: string): void {
    activeFormattingLocale = normalizeFormattingLocale(locale);
}

export function getFormattingLocale(): FormattingLocale {
    if (activeFormattingLocale) {
        return activeFormattingLocale;
    }

    if (typeof document !== 'undefined') {
        return normalizeFormattingLocale(document.documentElement.lang);
    }

    return 'ar-LY';
}

export function createLocaleNumberFormatter(
    options: Intl.NumberFormatOptions = {},
): LocaleNumberFormatter {
    const formatterOptions = { ...options };
    const formatters = new Map<FormattingLocale, Intl.NumberFormat>();

    return {
        format(value) {
            const locale = getFormattingLocale();
            let formatter = formatters.get(locale);

            if (!formatter) {
                formatter = new Intl.NumberFormat(locale, formatterOptions);
                formatters.set(locale, formatter);
            }

            return formatter.format(value);
        },
    };
}

export function createLocaleDateTimeFormatter(
    options: Intl.DateTimeFormatOptions = {},
): LocaleDateTimeFormatter {
    const formatterOptions = { ...options };
    const formatters = new Map<FormattingLocale, Intl.DateTimeFormat>();

    return {
        format(value) {
            const locale = getFormattingLocale();
            let formatter = formatters.get(locale);

            if (!formatter) {
                formatter = new Intl.DateTimeFormat(locale, formatterOptions);
                formatters.set(locale, formatter);
            }

            return formatter.format(value);
        },
    };
}

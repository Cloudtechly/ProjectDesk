export type LocaleCode = 'ar' | 'en';

export type TextDirection = 'rtl' | 'ltr';

export type SupportedLocale = {
    code: LocaleCode;
    dir: TextDirection;
    tag: string;
    label: string;
};

export type LocalizationState = SupportedLocale & {
    supported: SupportedLocale[];
};

export type LegacyLocalizationState = Partial<LocalizationState> & {
    locale?: LocaleCode;
    direction?: TextDirection;
};

export const fallbackSupportedLocales: SupportedLocale[] = [
    { code: 'ar', dir: 'rtl', tag: 'ar', label: 'العربية' },
    { code: 'en', dir: 'ltr', tag: 'en', label: 'English' },
];

export function isLocaleCode(value: unknown): value is LocaleCode {
    return value === 'ar' || value === 'en';
}

export function isTextDirection(value: unknown): value is TextDirection {
    return value === 'rtl' || value === 'ltr';
}

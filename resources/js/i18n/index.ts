export {
    defineCompatibilityCatalog,
    mergeCompatibilityCatalogs,
    registerCompatibilityCatalog,
} from '@/i18n/catalog';
export type {
    CompatibilityCatalog,
    CompatibilityPattern,
} from '@/i18n/catalog';
export { LanguageSwitcher } from '@/i18n/language-switcher';
export { LocaleRuntime } from '@/i18n/locale-runtime';
export { defineMessages, translateMessage } from '@/i18n/translator';
export type { LocalizedMessages, MessageParameters } from '@/i18n/translator';
export { useLocale } from '@/i18n/use-locale';

import type { LocaleCode } from '@/i18n/types';

export type MessageParameters = Record<string, number | string>;

export type LocalizedMessages<Key extends string = string> = Record<
    LocaleCode,
    Record<Key, string>
>;

export function defineMessages<
    const Arabic extends Record<string, string>,
>(messages: {
    ar: Arabic;
    en: { [Key in keyof Arabic]: string };
}): LocalizedMessages<Extract<keyof Arabic, string>> {
    return messages as LocalizedMessages<Extract<keyof Arabic, string>>;
}

export function translateMessage<Key extends string>(
    messages: LocalizedMessages<Key>,
    locale: LocaleCode,
    key: Key,
    parameters: MessageParameters = {},
): string {
    const template = messages[locale][key] ?? messages.ar[key] ?? key;

    return template.replace(/:([A-Za-z][A-Za-z0-9_]*)/g, (token, name) =>
        Object.hasOwn(parameters, name) ? String(parameters[name]) : token,
    );
}

export function englishExactMessages<Key extends string>(
    messages: LocalizedMessages<Key>,
): Record<string, string> {
    return Object.fromEntries(
        Object.keys(messages.ar)
            .filter((key) => !messages.ar[key as Key].includes(':'))
            .map((key) => [messages.ar[key as Key], messages.en[key as Key]]),
    );
}

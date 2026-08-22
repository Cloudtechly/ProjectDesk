import { usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import { commonMessages } from '@/i18n/messages/common';
import type { CommonMessageKey } from '@/i18n/messages/common';
import { translateMessage } from '@/i18n/translator';
import type { MessageParameters } from '@/i18n/translator';
import {
    fallbackSupportedLocales,
    isLocaleCode,
    isTextDirection,
} from '@/i18n/types';
import type {
    LegacyLocalizationState,
    LocaleCode,
    SupportedLocale,
    TextDirection,
} from '@/i18n/types';

function normalizeSupportedLocales(value: unknown): SupportedLocale[] {
    if (!Array.isArray(value)) {
        return fallbackSupportedLocales;
    }

    const supported = value.filter(
        (locale): locale is SupportedLocale =>
            typeof locale === 'object' &&
            locale !== null &&
            'code' in locale &&
            isLocaleCode(locale.code) &&
            'dir' in locale &&
            isTextDirection(locale.dir) &&
            'tag' in locale &&
            typeof locale.tag === 'string' &&
            'label' in locale &&
            typeof locale.label === 'string',
    );

    return supported.length > 0 ? supported : fallbackSupportedLocales;
}

export function useLocale() {
    const page = usePage();
    const localization = page.props.localization as
        LegacyLocalizationState | undefined;
    const codeValue = localization?.code ?? localization?.locale;
    const code: LocaleCode = isLocaleCode(codeValue) ? codeValue : 'ar';
    const directionValue = localization?.dir ?? localization?.direction;
    const direction: TextDirection = isTextDirection(directionValue)
        ? directionValue
        : code === 'ar'
          ? 'rtl'
          : 'ltr';
    const tag =
        typeof localization?.tag === 'string' && localization.tag
            ? localization.tag
            : code;
    const supported = useMemo(
        () => normalizeSupportedLocales(localization?.supported),
        [localization?.supported],
    );
    const t = useCallback(
        (key: CommonMessageKey, parameters?: MessageParameters) =>
            translateMessage(commonMessages, code, key, parameters),
        [code],
    );

    return useMemo(
        () => ({
            code,
            direction,
            isRtl: direction === 'rtl',
            locale: code,
            localeTag: tag,
            supported,
            t,
        }),
        [code, direction, supported, t, tag],
    );
}

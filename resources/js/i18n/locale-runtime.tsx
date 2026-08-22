import { usePage } from '@inertiajs/react';
import { useLayoutEffect, useMemo } from 'react';
import type { PropsWithChildren } from 'react';
import { useCompatibilityBridge } from '@/i18n/compatibility-bridge';
import { setFormattingLocale } from '@/i18n/formatters';
import { collectProtectedContent } from '@/i18n/protected-content';
import { useLocale } from '@/i18n/use-locale';

export function LocaleRuntime({ children }: PropsWithChildren) {
    const page = usePage();
    const { direction, locale, localeTag } = useLocale();
    setFormattingLocale(localeTag);
    const protectedContent = useMemo(
        () => collectProtectedContent(page.props),
        [page.props],
    );

    useLayoutEffect(() => {
        document.documentElement.lang = localeTag;
        document.documentElement.dir = direction;
        document.body.dataset.locale = locale;
        document.body.dataset.direction = direction;
    }, [direction, locale, localeTag]);

    useCompatibilityBridge(locale, protectedContent);

    return children;
}

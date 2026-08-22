import { router } from '@inertiajs/react';
import { Languages, LoaderCircle } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useLocale } from '@/i18n/use-locale';

type LanguageSwitcherProps = {
    variant?: 'sidebar' | 'surface';
};

export function LanguageSwitcher({
    variant = 'surface',
}: LanguageSwitcherProps) {
    const { locale, supported, t } = useLocale();
    const [processing, setProcessing] = useState(false);
    const [failed, setFailed] = useState(false);
    const nextLocale = useMemo(() => {
        const currentIndex = supported.findIndex(
            (candidate) => candidate.code === locale,
        );

        return supported[(currentIndex + 1) % supported.length];
    }, [locale, supported]);

    if (!nextLocale || nextLocale.code === locale) {
        return null;
    }

    const accessibleLabel = t('language.switchTo', {
        language: nextLocale.label,
    });

    const switchLanguage = () => {
        if (processing) {
            return;
        }

        setFailed(false);
        router.put(
            '/locale',
            { locale: nextLocale.code },
            {
                onError: () => setFailed(true),
                onFinish: () => setProcessing(false),
                onStart: () => setProcessing(true),
                preserveScroll: true,
                preserveState: false,
                replace: true,
            },
        );
    };

    return (
        <div
            className="cloudtech-language-switcher-wrap"
            data-no-translate
            data-variant={variant}
        >
            <button
                type="button"
                className="cloudtech-language-switcher"
                aria-label={accessibleLabel}
                aria-busy={processing}
                disabled={processing}
                title={accessibleLabel}
                onClick={switchLanguage}
            >
                <span className="cloudtech-language-switcher-icon">
                    {processing ? (
                        <LoaderCircle
                            className="animate-spin"
                            aria-hidden="true"
                        />
                    ) : (
                        <Languages aria-hidden="true" />
                    )}
                </span>
                <span className="cloudtech-language-switcher-label">
                    {nextLocale.label}
                </span>
                <span
                    className="cloudtech-language-switcher-code"
                    aria-hidden="true"
                >
                    {nextLocale.code.toUpperCase()}
                </span>
            </button>
            <span className="sr-only" role="status" aria-live="polite">
                {failed ? t('language.failed') : ''}
            </span>
        </div>
    );
}

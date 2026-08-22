import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import { useLocale } from '@/i18n';
import type { AppVariant } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
};

export function AppShell({ children, variant = 'sidebar' }: Props) {
    const isOpen = usePage().props.sidebarOpen;
    const { direction, t } = useLocale();
    const [sidebarOpen, setSidebarOpen] = useState(() => {
        if (typeof window === 'undefined') {
            return Boolean(isOpen ?? true);
        }

        return !window.matchMedia('(min-width: 901px) and (max-width: 1199px)')
            .matches;
    });

    useEffect(() => {
        const compactQuery = window.matchMedia(
            '(min-width: 901px) and (max-width: 1199px)',
        );
        const wideQuery = window.matchMedia('(min-width: 1200px)');

        const syncSidebar = () => {
            if (compactQuery.matches) {
                setSidebarOpen(false);
            } else if (wideQuery.matches) {
                setSidebarOpen(true);
            }
        };

        syncSidebar();
        compactQuery.addEventListener('change', syncSidebar);
        wideQuery.addEventListener('change', syncSidebar);

        return () => {
            compactQuery.removeEventListener('change', syncSidebar);
            wideQuery.removeEventListener('change', syncSidebar);
        };
    }, []);

    if (variant === 'header') {
        return (
            <div className="flex min-h-screen w-full flex-col">{children}</div>
        );
    }

    return (
        <div className="cloudtech-app" dir={direction}>
            <a className="cloudtech-skip-link" href="#main-content">
                {t('shell.skipToMain')}
            </a>
            <SidebarProvider
                open={sidebarOpen}
                onOpenChange={setSidebarOpen}
                className="cloudtech-shell"
            >
                {children}
            </SidebarProvider>
        </div>
    );
}

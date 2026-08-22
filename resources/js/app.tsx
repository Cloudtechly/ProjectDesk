import { createInertiaApp } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { installUnsavedHistoryGuard } from '@/hooks/use-unsaved-changes';
import { LocaleRuntime } from '@/i18n';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Project Desk';

installUnsavedHistoryGuard();

function RuntimeLayout({ children }: PropsWithChildren) {
    return (
        <LocaleRuntime>
            <TooltipProvider delayDuration={0}>
                {children}
                <Toaster />
            </TooltipProvider>
        </LocaleRuntime>
    );
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return RuntimeLayout;
            case name.startsWith('auth/'):
                return [RuntimeLayout, AuthLayout];
            case name.startsWith('settings/') && name !== 'settings/index':
                return [RuntimeLayout, AppLayout, SettingsLayout];
            default:
                return [RuntimeLayout, AppLayout];
        }
    },
    strictMode: true,
    progress: {
        color: '#16c8ce',
    },
});

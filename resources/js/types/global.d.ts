import type { LocalizationState } from '@/i18n/types';
import type { Auth } from '@/types/auth';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            canCreateTask: boolean;
            abilities: {
                viewDataCenter: boolean;
                viewSettings: boolean;
            };
            sidebarOpen: boolean;
            localization: LocalizationState;
            [key: string]: unknown;
        };
    }
}

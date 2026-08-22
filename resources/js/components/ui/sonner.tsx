import { Toaster as Sonner } from 'sonner';
import type { ToasterProps } from 'sonner';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { useLocale } from '@/i18n';

function Toaster({ ...props }: ToasterProps) {
    useFlashToast();
    const { direction } = useLocale();

    return (
        <Sonner
            theme="light"
            dir={direction}
            className="toaster group"
            position={direction === 'rtl' ? 'bottom-left' : 'bottom-right'}
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };

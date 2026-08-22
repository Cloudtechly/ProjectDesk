import { Link } from '@inertiajs/react';
import { BellRing, ShieldCheck, UserRound } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'الملف الشخصي',
        href: edit(),
        icon: UserRound,
    },
    {
        title: 'الأمان',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'التنبيهات',
        href: '/settings/notifications',
        icon: BellRing,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="cloudtech-page">
            <header className="cloudtech-page-head">
                <div>
                    <p className="cloudtech-eyebrow">الحساب الشخصي</p>
                    <h1>إعدادات الحساب</h1>
                    <p>حدّث بياناتك وراجع وسائل حماية حسابك في Project Desk.</p>
                </div>
            </header>

            <div className="grid items-start gap-6 lg:grid-cols-[13rem_minmax(0,1fr)] lg:gap-10">
                <aside className="min-w-0" aria-label="أقسام إعدادات الحساب">
                    <nav
                        className="flex gap-2 overflow-x-auto rounded-xl border border-border bg-white p-2 shadow-sm lg:flex-col lg:overflow-visible"
                        aria-label="التنقل بين الإعدادات"
                    >
                        {sidebarNavItems.map((item) => {
                            const isActive = isCurrentOrParentUrl(item.href);

                            return (
                                <Button
                                    key={toUrl(item.href)}
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    className={cn(
                                        'w-auto shrink-0 justify-start whitespace-nowrap lg:w-full',
                                        isActive &&
                                            'bg-accent text-accent-foreground',
                                    )}
                                >
                                    <Link
                                        href={item.href}
                                        aria-current={
                                            isActive ? 'page' : undefined
                                        }
                                    >
                                        {item.icon && (
                                            <item.icon
                                                className="h-4 w-4"
                                                aria-hidden="true"
                                            />
                                        )}
                                        {item.title}
                                    </Link>
                                </Button>
                            );
                        })}
                    </nav>
                </aside>

                <section
                    className="max-w-2xl min-w-0 space-y-12 rounded-2xl border border-border bg-white p-5 shadow-sm sm:p-7"
                    aria-label="محتوى الإعدادات"
                >
                    {children}
                </section>
            </div>
        </div>
    );
}

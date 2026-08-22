import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Building2,
    Database,
    FolderKanban,
    FileText,
    LayoutDashboard,
    Settings2,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { LanguageSwitcher, useLocale } from '@/i18n';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { abilities } = usePage().props as {
        abilities?: { viewDataCenter?: boolean; viewSettings?: boolean };
    };
    const { direction, t } = useLocale();
    const mainNavItems: NavItem[] = [
        {
            title: t('nav.dashboard'),
            href: dashboard(),
            icon: LayoutDashboard,
        },
        {
            title: t('nav.projects'),
            href: '/projects',
            icon: FolderKanban,
        },
        {
            title: t('nav.tasks'),
            href: '/tasks',
            icon: BarChart3,
        },
        {
            title: t('nav.clients'),
            href: '/clients',
            icon: Building2,
        },
        {
            title: t('nav.team'),
            href: '/team',
            icon: Users,
        },
        {
            title: t('nav.invoiceTemplates'),
            href: '/sales',
            icon: FileText,
        },
    ];
    const visibleNavItems = [
        ...mainNavItems,
        ...(abilities?.viewDataCenter
            ? [
                  {
                      title: t('nav.dataCenter'),
                      href: '/data-center',
                      icon: Database,
                  },
              ]
            : []),
        ...(abilities?.viewSettings
            ? [
                  {
                      title: t('nav.settings'),
                      href: '/settings',
                      icon: Settings2,
                  },
              ]
            : []),
    ] satisfies NavItem[];

    return (
        <Sidebar
            side={direction === 'rtl' ? 'right' : 'left'}
            collapsible="icon"
            variant="sidebar"
            className="cloudtech-sidebar"
        >
            <SidebarHeader className="cloudtech-sidebar-header">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            className="cloudtech-brand-button"
                        >
                            <Link
                                href={dashboard()}
                                prefetch
                                aria-label="CloudTech Project Desk"
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="cloudtech-sidebar-content">
                <NavMain items={visibleNavItems} label={t('nav.workspace')} />
            </SidebarContent>

            <SidebarFooter className="cloudtech-sidebar-footer">
                <LanguageSwitcher variant="sidebar" />
                <p className="cloudtech-sidebar-note">{t('shell.note')}</p>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

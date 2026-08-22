import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    BellOff,
    Building2,
    CalendarClock,
    CircleAlert,
    Clock3,
    FolderKanban,
    ListTodo,
    LoaderCircle,
    Paperclip,
    Plus,
    ReceiptText,
    Search,
    UserRound,
    X,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent as ReactKeyboardEvent } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import {
    createLocaleDateTimeFormatter,
    createLocaleNumberFormatter,
} from '@/i18n/formatters';
import { LanguageSwitcher } from '@/i18n/language-switcher';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type SearchResultType =
    | 'project'
    | 'task'
    | 'requirement'
    | 'client'
    | 'team'
    | 'sales'
    | 'sales_document'
    | 'document'
    | 'project_file';

type SearchResult = {
    id: string;
    type: SearchResultType;
    type_label: string;
    title: string;
    subtitle: string;
    href: string;
};

type SearchResponse = {
    data: SearchResult[];
};

type NotificationItem = {
    id: string;
    type: 'task' | 'meeting';
    tone: 'danger' | 'warning' | 'info';
    label: string;
    title: string;
    project: string;
    project_code: string;
    scheduled_at: string;
    open_url: string;
};

type NotificationCenter = {
    enabled: boolean;
    count: number;
    lead_hours: number;
    items: NotificationItem[];
};

const emptyNotificationCenter: NotificationCenter = {
    enabled: true,
    count: 0,
    lead_hours: 24,
    items: [],
};

const notificationDateFormatter = createLocaleDateTimeFormatter({
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
    timeZone: 'Africa/Tripoli',
});
const notificationNumberFormatter = createLocaleNumberFormatter();

const resultIcons = {
    project: FolderKanban,
    task: ListTodo,
    requirement: CircleAlert,
    client: Building2,
    team: UserRound,
    sales: CalendarClock,
    sales_document: ReceiptText,
    document: FolderKanban,
    project_file: Paperclip,
} satisfies Record<SearchResultType, typeof FolderKanban>;

function isEditableTarget(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.matches('input, textarea, select') || target.isContentEditable)
    );
}

function formatNotificationDate(value: string): string {
    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : notificationDateFormatter.format(date);
}

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { canCreateTask, notifications = emptyNotificationCenter } = usePage()
        .props as {
        canCreateTask: boolean;
        notifications?: NotificationCenter;
    };
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [isOpen, setIsOpen] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [searchedTerm, setSearchedTerm] = useState('');
    const [hasError, setHasError] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);
    const [notificationsOpen, setNotificationsOpen] = useState(false);
    const [openingNotificationId, setOpeningNotificationId] = useState<
        string | null
    >(null);
    const rootRef = useRef<HTMLFormElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const notificationRootRef = useRef<HTMLDivElement>(null);
    const notificationTriggerRef = useRef<HTMLButtonElement>(null);
    const notificationPanelRef = useRef<HTMLDivElement>(null);
    const notificationCloseRef = useRef<HTMLButtonElement>(null);
    const listboxId = useId();
    const notificationPanelId = useId();
    const notificationTitleId = useId();
    const notificationDescriptionId = useId();
    const trimmedQuery = query.trim();

    useEffect(() => {
        if (!notificationsOpen) {
            return;
        }

        const frame = window.requestAnimationFrame(() => {
            notificationCloseRef.current?.focus();
        });
        const handleOutside = (event: PointerEvent) => {
            if (!notificationRootRef.current?.contains(event.target as Node)) {
                setNotificationsOpen(false);
            }
        };
        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                setNotificationsOpen(false);
                notificationTriggerRef.current?.focus();

                return;
            }

            if (event.key !== 'Tab' || !notificationPanelRef.current) {
                return;
            }

            const focusable = Array.from(
                notificationPanelRef.current.querySelectorAll<HTMLElement>(
                    'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])',
                ),
            );
            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (!first || !last) {
                event.preventDefault();

                return;
            }

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('pointerdown', handleOutside);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            window.cancelAnimationFrame(frame);
            document.removeEventListener('pointerdown', handleOutside);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [notificationsOpen]);

    useEffect(() => {
        const handleShortcut = (event: KeyboardEvent) => {
            if (
                event.key !== '/' ||
                event.ctrlKey ||
                event.metaKey ||
                event.altKey ||
                isEditableTarget(event.target)
            ) {
                return;
            }

            event.preventDefault();
            inputRef.current?.focus();

            if (trimmedQuery.length >= 2) {
                setIsOpen(true);
            }
        };
        const handleOutside = (event: PointerEvent) => {
            if (!rootRef.current?.contains(event.target as Node)) {
                setIsOpen(false);
            }
        };

        document.addEventListener('keydown', handleShortcut);
        document.addEventListener('pointerdown', handleOutside);

        return () => {
            document.removeEventListener('keydown', handleShortcut);
            document.removeEventListener('pointerdown', handleOutside);
        };
    }, [trimmedQuery]);

    useEffect(() => {
        if (trimmedQuery.length < 2) {
            return;
        }

        const controller = new AbortController();
        const debounce = window.setTimeout(async () => {
            setIsLoading(true);
            setHasError(false);
            setIsOpen(true);

            try {
                const response = await fetch(
                    `/search?q=${encodeURIComponent(trimmedQuery)}`,
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );

                if (!response.ok) {
                    throw new Error('Search request failed');
                }

                const payload = (await response.json()) as SearchResponse;
                setResults(payload.data);
                setSearchedTerm(trimmedQuery);
                setActiveIndex(0);
            } catch (error) {
                if (
                    error instanceof DOMException &&
                    error.name === 'AbortError'
                ) {
                    return;
                }

                setResults([]);
                setSearchedTerm(trimmedQuery);
                setHasError(true);
            } finally {
                if (!controller.signal.aborted) {
                    setIsLoading(false);
                }
            }
        }, 280);

        return () => {
            window.clearTimeout(debounce);
            controller.abort();
        };
    }, [trimmedQuery]);

    const visitResult = (result: SearchResult) => {
        setIsOpen(false);
        router.visit(result.href);
    };

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const selected = results[activeIndex] ?? results[0];

        if (selected) {
            visitResult(selected);
        }
    };

    const handleKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            setIsOpen(false);
            inputRef.current?.blur();

            return;
        }

        if (!isOpen || results.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex((current) => (current + 1) % results.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex(
                (current) => (current - 1 + results.length) % results.length,
            );
        } else if (event.key === 'Enter') {
            event.preventDefault();
            visitResult(results[activeIndex] ?? results[0]);
        }
    };

    const showPanel = isOpen && trimmedQuery.length >= 2;
    const hasCompletedSearch = searchedTerm === trimmedQuery && !isLoading;

    return (
        <header className="cloudtech-topbar">
            <div className="cloudtech-topbar-context">
                <SidebarTrigger className="cloudtech-menu-trigger" />
                <div className="cloudtech-breadcrumb-wrap">
                    <span className="cloudtech-context-label">مساحة العمل</span>
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>

            <div className="cloudtech-topbar-actions">
                <form
                    ref={rootRef}
                    className="cloudtech-search"
                    role="search"
                    onSubmit={handleSubmit}
                >
                    <label className="sr-only" htmlFor="global-search">
                        ابحث في المشاريع والمهام والمتطلبات والعملاء والفريق
                        والمستندات
                    </label>
                    <button
                        className="cloudtech-search-trigger"
                        type="button"
                        aria-label="فتح البحث العام"
                        onClick={() => inputRef.current?.focus()}
                    >
                        <Search aria-hidden="true" />
                    </button>
                    <input
                        ref={inputRef}
                        id="global-search"
                        type="search"
                        placeholder="ابحث في العمل…"
                        autoComplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded={showPanel}
                        aria-controls={showPanel ? listboxId : undefined}
                        aria-activedescendant={
                            showPanel && results[activeIndex]
                                ? `${listboxId}-${results[activeIndex].id}`
                                : undefined
                        }
                        value={query}
                        onChange={(event) => {
                            const nextQuery = event.target.value;
                            setQuery(nextQuery);
                            setActiveIndex(0);

                            if (nextQuery.trim().length < 2) {
                                setResults([]);
                                setSearchedTerm('');
                                setIsLoading(false);
                                setHasError(false);
                            }
                        }}
                        onFocus={() => {
                            if (trimmedQuery.length >= 2) {
                                setIsOpen(true);
                            }
                        }}
                        onKeyDown={handleKeyDown}
                    />
                    {isLoading ? (
                        <LoaderCircle
                            className="cloudtech-search-loader"
                            aria-label="جارٍ البحث"
                        />
                    ) : (
                        <kbd aria-hidden="true">/</kbd>
                    )}

                    {showPanel && (
                        <div
                            className="cloudtech-search-results"
                            id={listboxId}
                            role="listbox"
                            aria-label="نتائج البحث العام"
                        >
                            {results.length > 0 ? (
                                <div className="cloudtech-search-result-list">
                                    {results.map((result, index) => {
                                        const Icon = resultIcons[result.type];

                                        return (
                                            <Link
                                                key={result.id}
                                                id={`${listboxId}-${result.id}`}
                                                className="cloudtech-search-result"
                                                data-active={
                                                    index === activeIndex
                                                }
                                                href={result.href}
                                                role="option"
                                                aria-selected={
                                                    index === activeIndex
                                                }
                                                onMouseEnter={() =>
                                                    setActiveIndex(index)
                                                }
                                                onClick={() => setIsOpen(false)}
                                            >
                                                <span className="cloudtech-search-result-icon">
                                                    <Icon aria-hidden="true" />
                                                </span>
                                                <span className="cloudtech-search-result-copy">
                                                    <span className="cloudtech-search-result-title">
                                                        {result.title}
                                                    </span>
                                                    <span className="cloudtech-search-result-meta">
                                                        <span>
                                                            {result.type_label}
                                                        </span>
                                                        <span aria-hidden="true">
                                                            ·
                                                        </span>
                                                        <span>
                                                            {result.subtitle}
                                                        </span>
                                                    </span>
                                                </span>
                                            </Link>
                                        );
                                    })}
                                </div>
                            ) : hasError ? (
                                <p
                                    className="cloudtech-search-state"
                                    role="alert"
                                >
                                    تعذر إكمال البحث. حاول مرة أخرى.
                                </p>
                            ) : hasCompletedSearch ? (
                                <p className="cloudtech-search-state">
                                    لا توجد نتائج مطابقة.
                                </p>
                            ) : (
                                <p
                                    className="cloudtech-search-state"
                                    aria-live="polite"
                                >
                                    جارٍ البحث…
                                </p>
                            )}
                        </div>
                    )}
                </form>
                <div className="cloudtech-topbar-language">
                    <LanguageSwitcher />
                </div>
                <div
                    ref={notificationRootRef}
                    className="cloudtech-notification-center"
                >
                    <button
                        ref={notificationTriggerRef}
                        className="cloudtech-notification-trigger"
                        type="button"
                        aria-label={
                            notifications.enabled
                                ? notifications.count > 0
                                    ? `التنبيهات، ${notificationNumberFormatter.format(notifications.count)} عناصر تحتاج الانتباه`
                                    : 'التنبيهات، لا توجد عناصر جديدة'
                                : 'التنبيهات متوقفة'
                        }
                        aria-haspopup="dialog"
                        aria-expanded={notificationsOpen}
                        aria-controls={
                            notificationsOpen ? notificationPanelId : undefined
                        }
                        onClick={() =>
                            setNotificationsOpen((current) => !current)
                        }
                    >
                        {notifications.enabled ? (
                            <Bell aria-hidden="true" />
                        ) : (
                            <BellOff aria-hidden="true" />
                        )}
                        {notifications.count > 0 && (
                            <span
                                className="cloudtech-notification-badge"
                                aria-hidden="true"
                            >
                                {notifications.count > 99
                                    ? '+٩٩'
                                    : notificationNumberFormatter.format(
                                          notifications.count,
                                      )}
                            </span>
                        )}
                    </button>

                    {notificationsOpen && (
                        <div
                            ref={notificationPanelRef}
                            id={notificationPanelId}
                            className="cloudtech-notification-panel"
                            role="dialog"
                            aria-modal="false"
                            aria-labelledby={notificationTitleId}
                            aria-describedby={notificationDescriptionId}
                            dir="rtl"
                        >
                            <div className="cloudtech-notification-head">
                                <div>
                                    <span className="cloudtech-notification-kicker">
                                        غرفة القيادة
                                    </span>
                                    <h2 id={notificationTitleId}>
                                        مركز التنبيهات
                                    </h2>
                                    <p id={notificationDescriptionId}>
                                        {notifications.enabled
                                            ? `المواعيد خلال ${notificationNumberFormatter.format(notifications.lead_hours)} ساعة بتوقيت طرابلس`
                                            : 'التنبيهات متوقفة حسب إعدادات حسابك أو النظام.'}
                                    </p>
                                </div>
                                <button
                                    ref={notificationCloseRef}
                                    className="cloudtech-notification-close"
                                    type="button"
                                    aria-label="إغلاق مركز التنبيهات"
                                    onClick={() => {
                                        setNotificationsOpen(false);
                                        notificationTriggerRef.current?.focus();
                                    }}
                                >
                                    <X aria-hidden="true" />
                                </button>
                            </div>

                            {notifications.enabled &&
                            notifications.items.length > 0 ? (
                                <div className="cloudtech-notification-list">
                                    {notifications.items.map((item) => {
                                        const ItemIcon =
                                            item.type === 'meeting'
                                                ? CalendarClock
                                                : item.tone === 'danger'
                                                  ? CircleAlert
                                                  : Clock3;

                                        return (
                                            <button
                                                key={item.id}
                                                type="button"
                                                className="cloudtech-notification-item"
                                                data-tone={item.tone}
                                                disabled={
                                                    openingNotificationId !==
                                                    null
                                                }
                                                aria-label={`${item.label}: ${item.title}، ${item.project}`}
                                                onClick={() => {
                                                    router.post(
                                                        item.open_url,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                            onStart: () =>
                                                                setOpeningNotificationId(
                                                                    item.id,
                                                                ),
                                                            onSuccess: () =>
                                                                setNotificationsOpen(
                                                                    false,
                                                                ),
                                                            onFinish: () =>
                                                                setOpeningNotificationId(
                                                                    null,
                                                                ),
                                                        },
                                                    );
                                                }}
                                            >
                                                <span className="cloudtech-notification-item-icon">
                                                    <ItemIcon aria-hidden="true" />
                                                </span>
                                                <span className="cloudtech-notification-item-copy">
                                                    <span className="cloudtech-notification-item-label">
                                                        {item.label}
                                                    </span>
                                                    <strong>
                                                        {item.title}
                                                    </strong>
                                                    <span className="cloudtech-notification-item-project">
                                                        {item.project_code} ·{' '}
                                                        {item.project}
                                                    </span>
                                                    <time
                                                        dateTime={
                                                            item.scheduled_at
                                                        }
                                                    >
                                                        {formatNotificationDate(
                                                            item.scheduled_at,
                                                        )}
                                                    </time>
                                                </span>
                                                {openingNotificationId ===
                                                    item.id && (
                                                    <LoaderCircle
                                                        className="cloudtech-notification-item-loader"
                                                        aria-hidden="true"
                                                    />
                                                )}
                                            </button>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="cloudtech-notification-empty">
                                    {notifications.enabled ? (
                                        <Bell aria-hidden="true" />
                                    ) : (
                                        <BellOff aria-hidden="true" />
                                    )}
                                    <strong>
                                        {notifications.enabled
                                            ? 'كل شيء تحت السيطرة'
                                            : 'التنبيهات متوقفة'}
                                    </strong>
                                    <p>
                                        {notifications.enabled
                                            ? 'لا توجد مهام متأخرة أو مواعيد قريبة ضمن المهلة الحالية.'
                                            : 'راجع تفضيلات حسابك؛ وقد تكون سياسة النظام العامة معطّلة أيضاً.'}
                                    </p>
                                </div>
                            )}

                            <div className="cloudtech-notification-footer">
                                {notifications.count >
                                    notifications.items.length && (
                                    <span>
                                        نعرض أحدث{' '}
                                        {notificationNumberFormatter.format(
                                            notifications.items.length,
                                        )}{' '}
                                        من{' '}
                                        {notificationNumberFormatter.format(
                                            notifications.count,
                                        )}
                                    </span>
                                )}
                                <Link
                                    href={
                                        notifications.enabled
                                            ? '/dashboard'
                                            : '/settings/notifications'
                                    }
                                    onClick={() => setNotificationsOpen(false)}
                                >
                                    {notifications.enabled
                                        ? 'فتح لوحة المتابعة'
                                        : 'مراجعة التفضيلات'}
                                </Link>
                            </div>
                        </div>
                    )}
                </div>
                {canCreateTask && (
                    <Link className="cloudtech-quick-add" href="/tasks/create">
                        <Plus aria-hidden="true" />
                        <span>إضافة مهمة</span>
                    </Link>
                )}
            </div>
        </header>
    );
}

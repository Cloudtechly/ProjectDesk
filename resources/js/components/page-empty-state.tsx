import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { ArrowLeft, Plus } from 'lucide-react';

type PageEmptyStateProps = {
    eyebrow: string;
    title: string;
    description: string;
    icon: LucideIcon;
    actionLabel?: string;
    actionHref?: string;
    embedded?: boolean;
};

export function PageEmptyState({
    eyebrow,
    title,
    description,
    icon: Icon,
    actionLabel,
    actionHref,
    embedded = false,
}: PageEmptyStateProps) {
    const emptyState = (
        <section
            className="cloudtech-empty-state"
            aria-labelledby="empty-state-title"
        >
            <div className="cloudtech-empty-icon">
                <Icon aria-hidden="true" />
            </div>
            <p className="cloudtech-empty-kicker">جاهز للبدء</p>
            <h2 id="empty-state-title">لا توجد بيانات بعد</h2>
            <p>{description}</p>
            {actionLabel && actionHref && (
                <Link className="cloudtech-text-action" href={actionHref}>
                    {actionLabel}
                    <ArrowLeft aria-hidden="true" />
                </Link>
            )}
        </section>
    );

    if (embedded) {
        return emptyState;
    }

    return (
        <div className="cloudtech-page">
            <header className="cloudtech-page-head">
                <div>
                    <p className="cloudtech-eyebrow">{eyebrow}</p>
                    <h1 tabIndex={-1}>{title}</h1>
                    <p>{description}</p>
                </div>
                {actionLabel && actionHref && (
                    <Link
                        className="cloudtech-primary-action"
                        href={actionHref}
                    >
                        <Plus aria-hidden="true" />
                        {actionLabel}
                    </Link>
                )}
            </header>

            {emptyState}
        </div>
    );
}

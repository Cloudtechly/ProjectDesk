import { Link } from '@inertiajs/react';

export type PaginationLink = {
    url?: string | null;
    label: string;
    active?: boolean;
};

function visibleLabel(link: PaginationLink, index: number, total: number) {
    if (index === 0) {
        return 'السابق';
    }

    if (index === total - 1) {
        return 'التالي';
    }

    const label = link.label.replace(/<[^>]*>/g, '').trim();

    return label === '&hellip;' ? '…' : label;
}

export function PaginationLinks({
    links,
    label,
}: {
    links?: PaginationLink[];
    label: string;
}) {
    if (!links || links.length <= 3) {
        return null;
    }

    return (
        <nav className="cloudtech-pagination" aria-label={label}>
            {links.map((link, index) => {
                const content = visibleLabel(link, index, links.length);
                const key = `${index}-${link.url ?? link.label}`;

                return link.url ? (
                    <Link
                        key={key}
                        href={link.url}
                        aria-current={link.active ? 'page' : undefined}
                        preserveScroll
                    >
                        {content}
                    </Link>
                ) : (
                    <span key={key} aria-disabled="true">
                        {content}
                    </span>
                );
            })}
        </nav>
    );
}

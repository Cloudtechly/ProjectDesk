import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    FileDown,
    Mail,
    Phone,
    Plus,
    RotateCcw,
    Search,
    UsersRound,
} from 'lucide-react';
import { PageEmptyState } from '@/components/page-empty-state';
import { PaginationLinks } from '@/components/pagination-links';
import { createLocaleNumberFormatter } from '@/i18n/formatters';
import { scopedXlsxUrl } from '@/lib/scoped-export';

type Contact = {
    id: number | string;
    name: string;
    role?: string | null;
    email?: string | null;
    phone?: string | null;
    is_primary?: boolean;
    is_active?: boolean;
};

type Client = {
    id: number | string;
    code: string;
    name: string;
    email?: string | null;
    phone?: string | null;
    address?: string | null;
    status?: string | null;
    archived_at?: string | null;
    projects_count?: number;
    contacts?: Contact[];
    can_restore?: boolean;
};

type Paginator<T> = {
    data?: T[];
    links?: Array<{
        url?: string | null;
        label: string;
        active?: boolean;
    }>;
};

type ClientsProps = {
    clients?: Paginator<Client> | Client[];
    filters?: {
        q?: string;
        status?: string;
        archived?: string;
        per_page?: number | string;
    };
    canCreate?: boolean;
};

const numberFormatter = createLocaleNumberFormatter();
const statusLabels: Record<string, string> = {
    active: 'نشط',
    inactive: 'غير نشط',
    archived: 'مؤرشف',
};

function collection<T>(value?: Paginator<T> | T[]) {
    return Array.isArray(value) ? value : (value?.data ?? []);
}

export default function ClientsIndex({
    clients,
    filters,
    canCreate = false,
}: ClientsProps) {
    const rows = collection(clients);
    const paginator = Array.isArray(clients) ? undefined : clients;
    const hasFilters = Boolean(
        filters?.q || filters?.status || filters?.archived === 'only',
    );

    return (
        <>
            <Head title="العملاء" />
            <div className="cloudtech-page">
                <header className="cloudtech-page-head">
                    <div>
                        <p className="cloudtech-eyebrow">دليل العلاقات</p>
                        <h1 tabIndex={-1}>العملاء وجهات الاتصال</h1>
                        <p>
                            اجمع بيانات التواصل والمشاريع المرتبطة دون فقد سياق
                            العلاقة.
                        </p>
                    </div>
                    <div className="cloudtech-page-actions">
                        <a
                            className="cloudtech-secondary-action"
                            href={scopedXlsxUrl('clients', filters)}
                        >
                            <FileDown aria-hidden="true" />
                            تصدير Excel
                        </a>
                        {canCreate && (
                            <Link
                                className="cloudtech-primary-action"
                                href="/clients/create"
                            >
                                <Plus aria-hidden="true" />
                                إضافة عميل
                            </Link>
                        )}
                    </div>
                </header>

                <form
                    className="cloudtech-filter-bar"
                    action="/clients"
                    method="get"
                >
                    <label className="cloudtech-filter-search">
                        <span className="sr-only">ابحث عن عميل</span>
                        <Search aria-hidden="true" />
                        <input
                            name="q"
                            defaultValue={filters?.q}
                            placeholder="الاسم، الرمز، الهاتف أو جهة الاتصال…"
                        />
                    </label>
                    <label>
                        <span className="sr-only">حالة العميل</span>
                        <select
                            name="status"
                            defaultValue={filters?.status || ''}
                        >
                            <option value="">كل الحالات</option>
                            <option value="active">نشط</option>
                            <option value="inactive">غير نشط</option>
                            <option value="archived">مؤرشف</option>
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">الأرشيف</span>
                        <select
                            name="archived"
                            defaultValue={filters?.archived || 'active'}
                        >
                            <option value="active">النشطون فقط</option>
                            <option value="only">المؤرشفون فقط</option>
                            <option value="all">الكل</option>
                        </select>
                    </label>
                    <button type="submit">تطبيق الفلاتر</button>
                    {hasFilters && <Link href="/clients">مسح</Link>}
                </form>

                {rows.length === 0 ? (
                    <PageEmptyState
                        eyebrow="دليل العلاقات"
                        title="لا توجد نتائج"
                        description="لم نعثر على عملاء بهذه الفلاتر. غيّر البحث أو أضف أول عميل."
                        icon={Building2}
                        actionLabel={canCreate ? 'إضافة عميل' : undefined}
                        actionHref={canCreate ? '/clients/create' : undefined}
                        embedded
                    />
                ) : (
                    <>
                        <div className="clients-grid">
                            {rows.map((client) => {
                                const primaryContact = client.contacts?.find(
                                    (contact) => contact.is_primary,
                                );

                                return (
                                    <article
                                        className="client-card"
                                        key={client.id}
                                    >
                                        <div className="client-card-head">
                                            <span
                                                className="client-avatar"
                                                aria-hidden="true"
                                            >
                                                {client.name.slice(0, 1)}
                                            </span>
                                            <div>
                                                <span
                                                    className="dashboard-code"
                                                    dir="ltr"
                                                >
                                                    {client.code}
                                                </span>
                                                <h2>
                                                    <Link
                                                        href={`/clients/${client.id}`}
                                                    >
                                                        {client.name}
                                                    </Link>
                                                </h2>
                                            </div>
                                            <span
                                                className={`client-status status-${client.status || 'active'}`}
                                            >
                                                {statusLabels[
                                                    client.status || 'active'
                                                ] || client.status}
                                            </span>
                                        </div>

                                        <dl className="client-card-details">
                                            <div>
                                                <dt>
                                                    <Mail aria-hidden="true" />
                                                    البريد
                                                </dt>
                                                <dd dir="ltr">
                                                    {client.email || 'غير مسجل'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>
                                                    <Phone aria-hidden="true" />
                                                    الهاتف
                                                </dt>
                                                <dd dir="ltr">
                                                    {client.phone || 'غير مسجل'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>
                                                    <UsersRound aria-hidden="true" />
                                                    جهة الاتصال الأساسية
                                                </dt>
                                                <dd>
                                                    {primaryContact?.name ||
                                                        'غير محددة'}
                                                </dd>
                                            </div>
                                        </dl>

                                        <footer className="client-card-footer">
                                            <span>
                                                {numberFormatter.format(
                                                    client.projects_count ?? 0,
                                                )}{' '}
                                                مشروع مرتبط
                                            </span>
                                            <Link
                                                href={`/clients/${client.id}`}
                                                aria-label={`فتح ملف ${client.name}`}
                                            >
                                                فتح الملف
                                                <ArrowLeft aria-hidden="true" />
                                            </Link>
                                            {client.can_restore && (
                                                <Form
                                                    action={`/clients/${client.id}/restore`}
                                                    method="post"
                                                >
                                                    {({ processing }) => (
                                                        <button
                                                            type="submit"
                                                            className="table-restore-button"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            <RotateCcw aria-hidden="true" />
                                                            استعادة
                                                        </button>
                                                    )}
                                                </Form>
                                            )}
                                        </footer>
                                    </article>
                                );
                            })}
                        </div>

                        <PaginationLinks
                            links={paginator?.links}
                            label="صفحات العملاء"
                        />
                    </>
                )}
            </div>
        </>
    );
}

ClientsIndex.layout = {
    breadcrumbs: [{ title: 'العملاء', href: '/clients' }],
};

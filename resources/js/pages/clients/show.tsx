import { Form, Head, Link } from '@inertiajs/react';
import {
    Archive,
    ArrowLeft,
    ArrowRight,
    Edit3,
    FolderKanban,
    Mail,
    MapPin,
    Phone,
    Plus,
    RotateCcw,
    Star,
    UserRound,
} from 'lucide-react';
import type { ClientRecord } from '@/components/clients/client-form';
import InputError from '@/components/input-error';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useUnsavedDialog } from '@/hooks/use-unsaved-changes';
import { createLocaleNumberFormatter } from '@/i18n/formatters';

type Contact = {
    id: number | string;
    name: string;
    role?: string | null;
    email?: string | null;
    phone?: string | null;
    is_primary?: boolean;
    is_active?: boolean;
    canUpdate?: boolean;
    canArchive?: boolean;
    canRestore?: boolean;
};

type Project = {
    id: number | string;
    code: string;
    name: string;
    priority?: string | null;
    status?: {
        label?: string;
        color?: string;
        semantic?: string;
    } | null;
};

type Client = ClientRecord & {
    projects_count?: number;
    contacts?: Contact[];
    projects?: Project[];
    can?: {
        update?: boolean;
        archive?: boolean;
        restore?: boolean;
        createContact?: boolean;
        createProject?: boolean;
    };
};

const numberFormatter = createLocaleNumberFormatter();
const statusLabels: Record<string, string> = {
    active: 'نشط',
    inactive: 'غير نشط',
    archived: 'مؤرشف',
};

function ContactDialog({
    client,
    contact,
}: {
    client: Client;
    contact?: Contact;
}) {
    const editing = Boolean(contact);
    const {
        allowNextNavigation,
        closeAfterSuccess,
        markDirty,
        onOpenChange,
        open,
    } = useUnsavedDialog(
        false,
        'لديك تغييرات غير محفوظة في جهة الاتصال. هل تريد تجاهلها؟',
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogTrigger asChild>
                <button
                    className={
                        editing
                            ? 'contact-action-button'
                            : 'client-secondary-action'
                    }
                    type="button"
                    aria-label={
                        editing
                            ? `تعديل جهة الاتصال ${contact?.name}`
                            : 'إضافة جهة اتصال'
                    }
                >
                    {editing ? (
                        <Edit3 aria-hidden="true" />
                    ) : (
                        <Plus aria-hidden="true" />
                    )}
                    {!editing && 'إضافة جهة اتصال'}
                </button>
            </DialogTrigger>
            <DialogContent className="cloudtech-dialog" dir="rtl">
                <DialogHeader className="text-start">
                    <p className="cloudtech-eyebrow">{client.name}</p>
                    <DialogTitle>
                        {editing ? 'تعديل جهة الاتصال' : 'جهة اتصال جديدة'}
                    </DialogTitle>
                    <DialogDescription>
                        أضف شخصاً للتواصل وحدد إن كان الجهة الأساسية لهذا
                        العميل.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={
                        editing
                            ? `/clients/${client.id}/contacts/${contact?.id}`
                            : `/clients/${client.id}/contacts`
                    }
                    method={editing ? 'put' : 'post'}
                    className="cloudtech-form"
                    onChange={markDirty}
                    onBefore={allowNextNavigation}
                    onSuccess={closeAfterSuccess}
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="cloudtech-form-grid two-columns">
                                <label>
                                    <span>الاسم</span>
                                    <input
                                        name="name"
                                        required
                                        autoFocus
                                        defaultValue={contact?.name}
                                        placeholder="اسم جهة الاتصال"
                                    />
                                    <InputError message={errors.name} />
                                </label>
                                <label>
                                    <span>المسمى أو الدور</span>
                                    <input
                                        name="role"
                                        defaultValue={contact?.role || ''}
                                        placeholder="مدير المشروع، المالية…"
                                    />
                                    <InputError message={errors.role} />
                                </label>
                                <label>
                                    <span>البريد الإلكتروني</span>
                                    <input
                                        name="email"
                                        type="email"
                                        dir="ltr"
                                        defaultValue={contact?.email || ''}
                                        placeholder="name@example.com"
                                    />
                                    <InputError message={errors.email} />
                                </label>
                                <label>
                                    <span>الهاتف</span>
                                    <input
                                        name="phone"
                                        type="tel"
                                        dir="ltr"
                                        defaultValue={contact?.phone || ''}
                                        placeholder="+218 …"
                                    />
                                    <InputError message={errors.phone} />
                                </label>
                            </div>
                            <div className="client-checks">
                                <label>
                                    <input
                                        type="hidden"
                                        name="is_primary"
                                        value="0"
                                    />
                                    <input
                                        type="checkbox"
                                        name="is_primary"
                                        value="1"
                                        defaultChecked={contact?.is_primary}
                                    />
                                    <span>جهة الاتصال الأساسية</span>
                                </label>
                                <label>
                                    <input
                                        type="hidden"
                                        name="is_active"
                                        value="0"
                                    />
                                    <input
                                        type="checkbox"
                                        name="is_active"
                                        value="1"
                                        defaultChecked={
                                            contact?.is_active ?? true
                                        }
                                    />
                                    <span>نشطة حالياً</span>
                                </label>
                            </div>
                            <button
                                className="cloudtech-primary-action"
                                type="submit"
                                disabled={processing}
                            >
                                {processing
                                    ? 'جارٍ الحفظ…'
                                    : editing
                                      ? 'حفظ التعديلات'
                                      : 'إضافة جهة الاتصال'}
                            </button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function ClientsShow({ client }: { client: Client }) {
    const contacts = client.contacts ?? [];
    const projects = client.projects ?? [];

    return (
        <>
            <Head title={client.name} />
            <div className="cloudtech-page client-profile-page">
                <Link className="project-back-link" href="/clients">
                    <ArrowRight aria-hidden="true" />
                    العودة إلى العملاء
                </Link>

                <section
                    className="client-profile-hero"
                    aria-labelledby="client-title"
                >
                    <div className="client-profile-brand" aria-hidden="true">
                        {client.name.slice(0, 1)}
                    </div>
                    <div className="client-profile-copy">
                        <div>
                            <span className="dashboard-code" dir="ltr">
                                {client.code}
                            </span>
                            <span
                                className={`client-status status-${client.status || 'active'}`}
                            >
                                {statusLabels[client.status || 'active'] ||
                                    client.status}
                            </span>
                        </div>
                        <h1 id="client-title" tabIndex={-1}>
                            {client.name}
                        </h1>
                        <p>
                            {numberFormatter.format(
                                client.projects_count ?? projects.length,
                            )}{' '}
                            مشروع مرتبط و
                            {numberFormatter.format(contacts.length)} جهة اتصال.
                        </p>
                    </div>
                    <div className="client-profile-actions">
                        {client.can?.createContact && (
                            <ContactDialog client={client} />
                        )}
                        {client.can?.update && (
                            <Link href={`/clients/${client.id}/edit`}>
                                <Edit3 aria-hidden="true" />
                                تعديل البيانات
                            </Link>
                        )}
                        {client.can?.archive && (
                            <Form
                                action={`/clients/${client.id}/archive`}
                                method="post"
                                onBefore={() =>
                                    window.confirm(
                                        `أرشفة ${client.name}؟ ستبقى المشاريع والسجلات محفوظة.`,
                                    )
                                }
                            >
                                {({ processing }) => (
                                    <button
                                        type="submit"
                                        className="client-archive-action"
                                        disabled={processing}
                                    >
                                        <Archive aria-hidden="true" />
                                        أرشفة العميل
                                    </button>
                                )}
                            </Form>
                        )}
                        {client.can?.restore && (
                            <Form
                                action={`/clients/${client.id}/restore`}
                                method="post"
                            >
                                {({ processing }) => (
                                    <button
                                        type="submit"
                                        className="client-secondary-action"
                                        disabled={processing}
                                    >
                                        <RotateCcw aria-hidden="true" />
                                        استعادة العميل
                                    </button>
                                )}
                            </Form>
                        )}
                    </div>
                </section>

                <div className="client-profile-grid">
                    <aside
                        className="client-info-panel"
                        aria-labelledby="client-info-title"
                    >
                        <p className="cloudtech-eyebrow">بطاقة التواصل</p>
                        <h2 id="client-info-title">معلومات العميل</h2>
                        <dl>
                            <div>
                                <dt>
                                    <Mail aria-hidden="true" />
                                    البريد الإلكتروني
                                </dt>
                                <dd dir="ltr">{client.email || 'غير مسجل'}</dd>
                            </div>
                            <div>
                                <dt>
                                    <Phone aria-hidden="true" />
                                    الهاتف
                                </dt>
                                <dd dir="ltr">{client.phone || 'غير مسجل'}</dd>
                            </div>
                            <div>
                                <dt>
                                    <MapPin aria-hidden="true" />
                                    العنوان البريدي
                                </dt>
                                <dd>{client.address || 'غير مسجل'}</dd>
                            </div>
                        </dl>
                    </aside>

                    <section
                        className="dashboard-panel client-contacts-panel"
                        aria-labelledby="contacts-title"
                    >
                        <div className="dashboard-panel-head">
                            <div>
                                <p className="cloudtech-eyebrow">
                                    العلاقة اليومية
                                </p>
                                <h2 id="contacts-title">جهات الاتصال</h2>
                            </div>
                            {client.can?.createContact && (
                                <ContactDialog client={client} />
                            )}
                        </div>
                        {contacts.length === 0 ? (
                            <div className="dashboard-section-empty">
                                <UserRound aria-hidden="true" />
                                <div>
                                    <strong>لا توجد جهات اتصال</strong>
                                    <p>أضف أول شخص للتواصل مع هذا العميل.</p>
                                </div>
                            </div>
                        ) : (
                            <ul className="client-contact-list">
                                {contacts.map((contact) => (
                                    <li
                                        key={contact.id}
                                        className={
                                            contact.is_active === false
                                                ? 'is-inactive'
                                                : undefined
                                        }
                                    >
                                        <span
                                            className="contact-avatar"
                                            aria-hidden="true"
                                        >
                                            {contact.name.slice(0, 1)}
                                        </span>
                                        <div>
                                            <strong>
                                                {contact.name}
                                                {contact.is_primary && (
                                                    <Star
                                                        aria-label="جهة الاتصال الأساسية"
                                                        fill="currentColor"
                                                    />
                                                )}
                                            </strong>
                                            <small>
                                                {contact.role || 'دون مسمى'}
                                            </small>
                                        </div>
                                        <div className="contact-channels">
                                            {contact.email && (
                                                <a
                                                    href={`mailto:${contact.email}`}
                                                    aria-label={`مراسلة ${contact.name}`}
                                                >
                                                    <Mail aria-hidden="true" />
                                                </a>
                                            )}
                                            {contact.phone && (
                                                <a
                                                    href={`tel:${contact.phone}`}
                                                    aria-label={`الاتصال بـ${contact.name}`}
                                                >
                                                    <Phone aria-hidden="true" />
                                                </a>
                                            )}
                                            {contact.canUpdate && (
                                                <ContactDialog
                                                    client={client}
                                                    contact={contact}
                                                />
                                            )}
                                            {contact.canArchive && (
                                                <Form
                                                    action={`/clients/${client.id}/contacts/${contact.id}/archive`}
                                                    method="post"
                                                    onBefore={() =>
                                                        window.confirm(
                                                            `أرشفة جهة الاتصال ${contact.name}؟`,
                                                        )
                                                    }
                                                >
                                                    {({ processing }) => (
                                                        <button
                                                            type="submit"
                                                            className="contact-action-button danger"
                                                            disabled={
                                                                processing
                                                            }
                                                            aria-label={`أرشفة جهة الاتصال ${contact.name}`}
                                                        >
                                                            <Archive aria-hidden="true" />
                                                        </button>
                                                    )}
                                                </Form>
                                            )}
                                            {contact.canRestore && (
                                                <Form
                                                    action={`/clients/${client.id}/contacts/${contact.id}/restore`}
                                                    method="post"
                                                >
                                                    {({ processing }) => (
                                                        <button
                                                            type="submit"
                                                            className="contact-action-button"
                                                            disabled={
                                                                processing
                                                            }
                                                            aria-label={`استعادة جهة الاتصال ${contact.name}`}
                                                        >
                                                            <RotateCcw aria-hidden="true" />
                                                        </button>
                                                    )}
                                                </Form>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <section
                    className="dashboard-panel client-projects-panel"
                    aria-labelledby="client-projects-title"
                >
                    <div className="dashboard-panel-head">
                        <div>
                            <p className="cloudtech-eyebrow">سياق العلاقة</p>
                            <h2 id="client-projects-title">
                                المشاريع المرتبطة
                            </h2>
                        </div>
                        <div className="client-project-actions">
                            {client.can?.createProject && (
                                <Link
                                    className="cloudtech-primary-action"
                                    href={`/projects?create=1&client=${client.id}`}
                                >
                                    <Plus aria-hidden="true" />
                                    مشروع جديد لهذا العميل
                                </Link>
                            )}
                            <Link href="/projects">
                                كل المشاريع
                                <ArrowLeft aria-hidden="true" />
                            </Link>
                        </div>
                    </div>
                    {projects.length === 0 ? (
                        <div className="dashboard-section-empty">
                            <FolderKanban aria-hidden="true" />
                            <div>
                                <strong>لا توجد مشاريع مرتبطة</strong>
                                <p>اختر هذا العميل عند إنشاء المشروع القادم.</p>
                            </div>
                        </div>
                    ) : (
                        <div className="client-projects-list">
                            {projects.map((project) => (
                                <Link
                                    key={project.id}
                                    href={`/projects/${project.id}`}
                                >
                                    <div>
                                        <span
                                            className="dashboard-code"
                                            dir="ltr"
                                        >
                                            {project.code}
                                        </span>
                                        <strong>{project.name}</strong>
                                    </div>
                                    <span
                                        className="table-status"
                                        style={
                                            {
                                                '--status-color':
                                                    project.status?.color ||
                                                    '#406386',
                                            } as React.CSSProperties
                                        }
                                    >
                                        {project.status?.label || 'دون حالة'}
                                    </span>
                                    <ArrowLeft aria-hidden="true" />
                                </Link>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

ClientsShow.layout = {
    breadcrumbs: [
        { title: 'العملاء', href: '/clients' },
        { title: 'ملف العميل', href: '#' },
    ],
};

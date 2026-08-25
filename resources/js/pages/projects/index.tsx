import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarDays,
    FileDown,
    FolderKanban,
    Plus,
    Search,
} from 'lucide-react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { PageEmptyState } from '@/components/page-empty-state';
import { PaginationLinks } from '@/components/pagination-links';
import { ExistingProjectDialog } from '@/components/projects/existing-project-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useUnsavedDialog } from '@/hooks/use-unsaved-changes';
import {
    createLocaleDateTimeFormatter,
    createLocaleNumberFormatter,
} from '@/i18n/formatters';
import { scopedXlsxUrl } from '@/lib/scoped-export';

type Project = {
    id: number | string;
    code: string;
    name: string;
    client?: string | null;
    status?: string | null;
    statusColor?: string | null;
    manager?: string | null;
    priority?: string | null;
    progress?: number | null;
    health?: string | null;
    openTasks?: number | null;
    overdueTasks?: number | null;
    nextStage?: {
        id: number | string;
        title: string;
        kind?: string | null;
        status?: string | null;
        starts_at?: string | null;
    } | null;
    currentPhase?: {
        id: number | string;
        title: string;
        progress?: number;
    } | null;
    nextMilestone?: {
        id: number | string;
        title: string;
        starts_at?: string | null;
    } | null;
    startDate?: string | null;
    endDate?: string | null;
    archivedAt?: string | null;
    lockVersion?: number;
    canRestore?: boolean;
};

type Option = {
    id: number | string;
    name?: string;
    label?: string;
    global_role?: string;
    contacts?: Array<{ id: number | string; name: string }>;
};

type Paginator<T> = {
    data?: T[];
    links?: Array<{ url?: string | null; label: string; active?: boolean }>;
    current_page?: number;
    last_page?: number;
    total?: number;
};

type ProjectsProps = {
    projects?: Paginator<Project> | Project[];
    filters?: {
        q?: string;
        status?: string;
        priority?: string;
        client?: string;
        sort?: string;
        direction?: string;
        scope?: string;
        activity?: string;
        risk?: string;
        health?: string;
    };
    statuses?: Option[];
    taskStatuses?: Option[];
    clients?: Option[];
    members?: Option[];
    canCreate?: boolean;
};

const numberFormatter = createLocaleNumberFormatter();
const dateFormatter = createLocaleDateTimeFormatter({
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

const priorityLabels: Record<string, string> = {
    low: 'منخفضة',
    medium: 'متوسطة',
    high: 'عالية',
    critical: 'حرجة',
};

const healthLabels: Record<string, string> = {
    danger: 'تحتاج تدخلاً',
    attention: 'تحتاج متابعة',
    healthy: 'مستقرة',
};

function collection<T>(value?: Paginator<T> | T[]) {
    return Array.isArray(value) ? value : (value?.data ?? []);
}

function formatDate(value?: string | null) {
    if (!value) {
        return 'غير محدد';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

function ProjectForm({
    statuses = [],
    clients = [],
    members = [],
    initialClientId = '',
    onDirtyChange,
    onBeforeSubmit,
    onSuccess,
}: Pick<ProjectsProps, 'statuses' | 'clients' | 'members'> & {
    initialClientId?: string;
    onDirtyChange?: () => void;
    onBeforeSubmit?: () => void;
    onSuccess?: () => void;
}) {
    const [step, setStep] = useState(1);
    const [clientId, setClientId] = useState(initialClientId);
    const formId = useId();
    const contacts =
        clients.find((client) => String(client.id) === clientId)?.contacts ??
        [];

    function nextStep() {
        const panel = document
            .getElementById(formId)
            ?.querySelector<HTMLElement>(
                `.project-wizard-panel[data-step="${step}"]`,
            );
        const invalid = Array.from(
            panel?.querySelectorAll<
                HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
            >('input, select, textarea') ?? [],
        ).find((field) => !field.checkValidity());

        if (invalid) {
            invalid.reportValidity();
            invalid.focus();

            return;
        }

        setStep((current) => Math.min(3, current + 1));
    }

    return (
        <Form
            id={formId}
            action="/projects"
            method="post"
            className="cloudtech-form"
            onChange={onDirtyChange}
            onBefore={onBeforeSubmit}
            onSuccess={onSuccess}
            onError={(errors) => {
                const names = Object.keys(errors);

                if (
                    names.some((name) =>
                        ['code', 'name', 'description'].includes(name),
                    )
                ) {
                    setStep(1);
                } else if (
                    names.some((name) =>
                        [
                            'client_id',
                            'primary_contact_id',
                            'manager_id',
                            'member_ids',
                        ].some((prefix) => name.startsWith(prefix)),
                    )
                ) {
                    setStep(2);
                } else {
                    setStep(3);
                }
            }}
        >
            {({ errors, processing }) => (
                <>
                    <ol
                        className="project-wizard-steps"
                        aria-label="خطوات إنشاء المشروع"
                    >
                        {['الأساسيات', 'العميل والفريق', 'الجدول والحالة'].map(
                            (label, index) => (
                                <li
                                    key={label}
                                    aria-current={
                                        step === index + 1 ? 'step' : undefined
                                    }
                                    className={
                                        step > index + 1 ? 'is-complete' : ''
                                    }
                                >
                                    <span>{index + 1}</span>
                                    {label}
                                </li>
                            ),
                        )}
                    </ol>

                    <section
                        hidden={step !== 1}
                        className="project-wizard-panel"
                        data-step="1"
                    >
                        <div className="cloudtech-form-grid two-columns">
                            <label>
                                <span>رمز المشروع</span>
                                <input
                                    name="code"
                                    required
                                    placeholder="PRJ-001"
                                    dir="ltr"
                                />
                                <InputError message={errors.code} />
                            </label>
                            <label>
                                <span>اسم المشروع</span>
                                <input
                                    name="name"
                                    required
                                    placeholder="اسم واضح للمشروع"
                                />
                                <InputError message={errors.name} />
                            </label>
                        </div>
                        <label>
                            <span>الوصف</span>
                            <textarea
                                name="description"
                                rows={3}
                                placeholder="نطاق العمل أو النتيجة المتوقعة"
                            />
                            <InputError message={errors.description} />
                        </label>
                    </section>

                    <section
                        hidden={step !== 2}
                        className="project-wizard-panel"
                        data-step="2"
                    >
                        <div className="cloudtech-form-grid two-columns">
                            <label>
                                <span>العميل</span>
                                <select
                                    name="client_id"
                                    value={clientId}
                                    onChange={(event) =>
                                        setClientId(event.target.value)
                                    }
                                >
                                    <option value="">دون عميل</option>
                                    {clients.map((client) => (
                                        <option
                                            key={client.id}
                                            value={client.id}
                                        >
                                            {client.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.client_id} />
                            </label>
                            <label>
                                <span>جهة الاتصال الأساسية</span>
                                <select
                                    name="primary_contact_id"
                                    defaultValue=""
                                >
                                    <option value="">دون جهة محددة</option>
                                    {contacts.map((contact) => (
                                        <option
                                            key={contact.id}
                                            value={contact.id}
                                        >
                                            {contact.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={errors.primary_contact_id}
                                />
                            </label>
                            <label>
                                <span>مدير المشروع</span>
                                <select name="manager_id" defaultValue="">
                                    <option value="">غير محدد</option>
                                    {members
                                        .filter(
                                            (member) =>
                                                member.global_role !== 'viewer',
                                        )
                                        .map((member) => (
                                            <option
                                                key={member.id}
                                                value={member.id}
                                            >
                                                {member.name}
                                            </option>
                                        ))}
                                </select>
                                <InputError message={errors.manager_id} />
                            </label>
                        </div>
                        <fieldset className="project-member-picker">
                            <legend>أعضاء الفريق</legend>
                            {members.length === 0 ? (
                                <p>لا يوجد أعضاء نشطون متاحون.</p>
                            ) : (
                                <div>
                                    {members.map((member, index) => (
                                        <label key={member.id}>
                                            <input
                                                type="hidden"
                                                name={`members[${index}][id]`}
                                                value={member.id}
                                            />
                                            <span>{member.name}</span>
                                            <select
                                                name={`members[${index}][role]`}
                                                defaultValue=""
                                                aria-label={`دور ${member.name}`}
                                            >
                                                <option value="">
                                                    غير مضاف
                                                </option>
                                                {member.global_role !==
                                                    'viewer' && (
                                                    <>
                                                        <option value="manager">
                                                            مدير
                                                        </option>
                                                        <option value="member">
                                                            عضو
                                                        </option>
                                                    </>
                                                )}
                                                <option value="viewer">
                                                    مشاهد
                                                </option>
                                            </select>
                                        </label>
                                    ))}
                                </div>
                            )}
                            <InputError
                                message={errors.members || errors.member_ids}
                            />
                        </fieldset>
                    </section>

                    <section
                        hidden={step !== 3}
                        className="project-wizard-panel"
                        data-step="3"
                    >
                        <div className="cloudtech-form-grid two-columns">
                            <label>
                                <span>الحالة</span>
                                <select
                                    name="status_id"
                                    required
                                    defaultValue=""
                                >
                                    <option value="" disabled>
                                        اختر الحالة
                                    </option>
                                    {statuses.map((status) => (
                                        <option
                                            key={status.id}
                                            value={status.id}
                                        >
                                            {status.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.status_id} />
                            </label>
                            <label>
                                <span>الأولوية</span>
                                <select
                                    name="priority"
                                    required
                                    defaultValue="medium"
                                >
                                    {Object.entries(priorityLabels).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                                <InputError message={errors.priority} />
                            </label>
                            <label>
                                <span>تاريخ البداية</span>
                                <input name="start_date" type="date" />
                                <InputError message={errors.start_date} />
                            </label>
                            <label>
                                <span>تاريخ النهاية</span>
                                <input name="end_date" type="date" />
                                <InputError message={errors.end_date} />
                            </label>
                        </div>
                    </section>

                    <div className="project-wizard-actions">
                        {step > 1 && (
                            <button
                                type="button"
                                onClick={() =>
                                    setStep((current) => current - 1)
                                }
                            >
                                السابق
                            </button>
                        )}
                        {step < 3 ? (
                            <button
                                className="cloudtech-primary-action"
                                type="button"
                                onClick={nextStep}
                            >
                                التالي
                            </button>
                        ) : (
                            <button
                                className="cloudtech-primary-action"
                                type="submit"
                                disabled={processing}
                            >
                                {processing ? 'جارٍ الإنشاء…' : 'إنشاء المشروع'}
                            </button>
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}

function CreateProjectDialog(
    props: Pick<ProjectsProps, 'statuses' | 'clients' | 'members'> & {
        initialClientId?: string;
        defaultOpen?: boolean;
    },
) {
    const {
        allowNextNavigation,
        closeAfterSuccess,
        markDirty,
        onOpenChange,
        open,
    } = useUnsavedDialog(
        Boolean(props.defaultOpen),
        'لديك تغييرات غير محفوظة في المشروع. هل تريد تجاهلها؟',
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogTrigger asChild>
                <button className="cloudtech-primary-action" type="button">
                    <Plus aria-hidden="true" />
                    إنشاء مشروع
                </button>
            </DialogTrigger>
            <DialogContent className="cloudtech-dialog" dir="rtl">
                <DialogHeader className="text-start">
                    <p className="cloudtech-eyebrow">مشروع جديد</p>
                    <DialogTitle>ابدأ بالأساسيات</DialogTitle>
                    <DialogDescription>
                        يمكنك إنشاء المشروع الآن وإضافة المهام في أي وقت لاحق.
                    </DialogDescription>
                </DialogHeader>
                <ProjectForm
                    {...props}
                    onDirtyChange={markDirty}
                    onBeforeSubmit={allowNextNavigation}
                    onSuccess={closeAfterSuccess}
                />
            </DialogContent>
        </Dialog>
    );
}

export default function ProjectsIndex({
    projects,
    filters,
    statuses = [],
    taskStatuses = [],
    clients = [],
    members = [],
    canCreate = false,
}: ProjectsProps) {
    const { url } = usePage();
    const query = new URL(url, 'http://localhost').searchParams;
    const requestedClientId = query.get('client') || '';
    const openCreateFromContext =
        canCreate &&
        query.get('create') === '1' &&
        clients.some((client) => String(client.id) === requestedClientId);
    const rows = collection(projects);
    const paginator = Array.isArray(projects) ? undefined : projects;

    return (
        <>
            <Head title="المشاريع" />
            <div className="cloudtech-page">
                <header className="cloudtech-page-head">
                    <div>
                        <p className="cloudtech-eyebrow">محفظة المشاريع</p>
                        <h1 tabIndex={-1}>المشاريع</h1>
                        <p>
                            تابع الحالة والتقدم والموعد القادم لكل مشروع من مكان
                            واحد.
                        </p>
                    </div>
                    <div className="cloudtech-page-actions">
                        <a
                            className="cloudtech-secondary-action"
                            href={scopedXlsxUrl('projects', filters)}
                        >
                            <FileDown aria-hidden="true" />
                            تصدير Excel
                        </a>
                        {canCreate && (
                            <>
                                <ExistingProjectDialog
                                    statuses={statuses}
                                    taskStatuses={taskStatuses}
                                    clients={clients}
                                    members={members}
                                />
                                <CreateProjectDialog
                                    statuses={statuses}
                                    clients={clients}
                                    members={members}
                                    initialClientId={requestedClientId}
                                    defaultOpen={openCreateFromContext}
                                />
                            </>
                        )}
                    </div>
                </header>

                <form
                    className="cloudtech-filter-bar"
                    action="/projects"
                    method="get"
                >
                    {filters?.activity && (
                        <input
                            type="hidden"
                            name="activity"
                            value={filters.activity}
                        />
                    )}
                    {filters?.risk && (
                        <input type="hidden" name="risk" value={filters.risk} />
                    )}
                    {filters?.health && (
                        <input
                            type="hidden"
                            name="health"
                            value={filters.health}
                        />
                    )}
                    <label className="cloudtech-filter-search">
                        <span className="sr-only">ابحث عن مشروع</span>
                        <Search aria-hidden="true" />
                        <input
                            name="q"
                            defaultValue={filters?.q}
                            placeholder="ابحث بالاسم أو الرمز…"
                        />
                    </label>
                    <label>
                        <span className="sr-only">الحالة</span>
                        <select
                            name="status"
                            defaultValue={filters?.status || ''}
                        >
                            <option value="">كل الحالات</option>
                            {statuses.map((status) => (
                                <option key={status.id} value={status.id}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">الأولوية</span>
                        <select
                            name="priority"
                            defaultValue={filters?.priority || ''}
                        >
                            <option value="">كل الأولويات</option>
                            {Object.entries(priorityLabels).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">العميل</span>
                        <select
                            name="client"
                            defaultValue={filters?.client || ''}
                        >
                            <option value="">كل العملاء</option>
                            {clients.map((client) => (
                                <option key={client.id} value={client.id}>
                                    {client.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">ترتيب المشاريع</span>
                        <select
                            name="sort"
                            defaultValue={filters?.sort || 'end_date'}
                        >
                            <option value="end_date">الموعد النهائي</option>
                            <option value="start_date">تاريخ البداية</option>
                            <option value="name">اسم المشروع</option>
                            <option value="priority">الأولوية</option>
                            <option value="created_at">تاريخ الإنشاء</option>
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">اتجاه الترتيب</span>
                        <select
                            name="direction"
                            defaultValue={filters?.direction || 'asc'}
                        >
                            <option value="asc">تصاعدي</option>
                            <option value="desc">تنازلي</option>
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">نطاق المشاريع</span>
                        <select
                            name="scope"
                            defaultValue={filters?.scope || 'active'}
                        >
                            <option value="active">المشاريع النشطة</option>
                            <option value="archived">المشاريع المؤرشفة</option>
                        </select>
                    </label>
                    <button type="submit">تطبيق الفلاتر</button>
                    {(filters?.q ||
                        filters?.status ||
                        filters?.priority ||
                        filters?.client ||
                        filters?.activity ||
                        filters?.risk ||
                        filters?.health ||
                        filters?.sort !== 'end_date' ||
                        filters?.direction === 'desc' ||
                        filters?.scope === 'archived') && (
                        <Link href="/projects">مسح</Link>
                    )}
                </form>

                {(filters?.activity === 'active' ||
                    filters?.risk === 'high' ||
                    filters?.health) && (
                    <div className="cloudtech-filter-context" role="status">
                        <span>
                            {filters?.risk === 'high'
                                ? 'عرض المشاريع التي لديها مخاطر مرتفعة مفتوحة'
                                : filters?.health
                                  ? `عرض المشاريع ذات الصحة: ${healthLabels[filters.health] ?? filters.health}`
                                  : 'عرض المشاريع النشطة فقط'}
                        </span>
                        <Link href="/projects">مسح فلتر لوحة المتابعة</Link>
                    </div>
                )}

                <p className="cloudtech-result-count" aria-live="polite">
                    {numberFormatter.format(paginator?.total ?? rows.length)}{' '}
                    مشروعاً مطابقاً
                </p>

                {rows.length === 0 ? (
                    <PageEmptyState
                        eyebrow="محفظة المشاريع"
                        title="لا توجد نتائج"
                        description="لم تُعثر على مشاريع بهذه الفلاتر. غيّر البحث أو أنشئ مشروعاً جديداً."
                        icon={FolderKanban}
                        embedded
                    />
                ) : (
                    <>
                        <div className="cloudtech-table-shell">
                            <table className="cloudtech-data-table">
                                <caption className="sr-only">
                                    قائمة المشاريع
                                </caption>
                                <thead>
                                    <tr>
                                        <th scope="col">المشروع</th>
                                        <th scope="col">العميل والحالة</th>
                                        <th scope="col">التقدم</th>
                                        <th scope="col">المهام</th>
                                        <th scope="col">المرحلة القادمة</th>
                                        <th scope="col">الموعد</th>
                                        <th scope="col">
                                            <span className="sr-only">فتح</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((project) => {
                                        const progress = Math.min(
                                            100,
                                            Math.max(0, project.progress ?? 0),
                                        );

                                        return (
                                            <tr key={project.id}>
                                                <td data-label="المشروع">
                                                    <Link
                                                        className="table-primary-cell"
                                                        href={`/projects/${project.id}`}
                                                    >
                                                        <span
                                                            className="dashboard-code"
                                                            dir="ltr"
                                                        >
                                                            {project.code}
                                                        </span>
                                                        <span>
                                                            <strong>
                                                                {project.name}
                                                            </strong>
                                                            <small>
                                                                {project.manager ||
                                                                    'دون مدير'}
                                                            </small>
                                                        </span>
                                                    </Link>
                                                </td>
                                                <td data-label="العميل والحالة">
                                                    <span className="table-stack">
                                                        <strong>
                                                            {project.client ||
                                                                'دون عميل'}
                                                        </strong>
                                                        <small>
                                                            {project.status ||
                                                                'دون حالة'}
                                                        </small>
                                                        <span className="table-project-indicators">
                                                            <span>
                                                                {priorityLabels[
                                                                    project.priority ||
                                                                        ''
                                                                ] ||
                                                                    'دون أولوية'}
                                                            </span>
                                                            <span
                                                                className={`health-${project.health || 'healthy'}`}
                                                            >
                                                                {healthLabels[
                                                                    project.health ||
                                                                        ''
                                                                ] ||
                                                                    'دون مؤشر صحة'}
                                                            </span>
                                                        </span>
                                                    </span>
                                                </td>
                                                <td data-label="التقدم">
                                                    <div className="table-progress">
                                                        <span>
                                                            {numberFormatter.format(
                                                                progress,
                                                            )}
                                                            ٪
                                                        </span>
                                                        <div>
                                                            <i
                                                                style={{
                                                                    width: `${progress}%`,
                                                                }}
                                                            />
                                                        </div>
                                                    </div>
                                                </td>
                                                <td data-label="المهام">
                                                    <span className="table-task-count">
                                                        <strong>
                                                            {project.openTasks ??
                                                                '—'}
                                                        </strong>{' '}
                                                        مفتوحة
                                                        {(project.overdueTasks ??
                                                            0) > 0 && (
                                                            <small>
                                                                {
                                                                    project.overdueTasks
                                                                }{' '}
                                                                متأخرة
                                                            </small>
                                                        )}
                                                    </span>
                                                </td>
                                                <td data-label="المرحلة القادمة">
                                                    {project.nextMilestone ||
                                                    project.currentPhase ||
                                                    project.nextStage ? (
                                                        <span className="table-next-stage">
                                                            <strong>
                                                                {project
                                                                    .nextMilestone
                                                                    ?.title ||
                                                                    project
                                                                        .currentPhase
                                                                        ?.title ||
                                                                    project
                                                                        .nextStage
                                                                        ?.title}
                                                            </strong>
                                                            <small>
                                                                {formatDate(
                                                                    project
                                                                        .nextMilestone
                                                                        ?.starts_at ||
                                                                        project
                                                                            .nextStage
                                                                            ?.starts_at,
                                                                )}
                                                            </small>
                                                        </span>
                                                    ) : (
                                                        <span className="table-muted-value">
                                                            لا توجد مرحلة قادمة
                                                        </span>
                                                    )}
                                                </td>
                                                <td data-label="الموعد">
                                                    <span className="table-date">
                                                        <CalendarDays aria-hidden="true" />
                                                        {formatDate(
                                                            project.endDate,
                                                        )}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span className="table-row-actions">
                                                        <Link
                                                            className="table-open-link"
                                                            href={`/projects/${project.id}`}
                                                            aria-label={`فتح ${project.name}`}
                                                        >
                                                            <ArrowLeft aria-hidden="true" />
                                                        </Link>
                                                        {project.canRestore && (
                                                            <Form
                                                                action={`/projects/${project.id}/restore`}
                                                                method="post"
                                                            >
                                                                {({
                                                                    processing,
                                                                }) => (
                                                                    <>
                                                                        <input
                                                                            type="hidden"
                                                                            name="lock_version"
                                                                            value={
                                                                                project.lockVersion ??
                                                                                1
                                                                            }
                                                                        />
                                                                        <button
                                                                            type="submit"
                                                                            disabled={
                                                                                processing
                                                                            }
                                                                            className="table-restore-button"
                                                                            aria-label={`استعادة ${project.name}`}
                                                                        >
                                                                            استعادة
                                                                        </button>
                                                                    </>
                                                                )}
                                                            </Form>
                                                        )}
                                                    </span>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <PaginationLinks
                            links={paginator?.links}
                            label="صفحات المشاريع"
                        />
                    </>
                )}
            </div>
        </>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [{ title: 'المشاريع', href: '/projects' }],
};

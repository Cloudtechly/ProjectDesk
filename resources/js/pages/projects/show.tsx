import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    ArrowRight,
    Building2,
    CalendarCheck2,
    CalendarRange,
    CheckCircle2,
    CircleDot,
    ClipboardList,
    FileText,
    FileDown,
    Flag,
    History,
    ListChecks,
    Paperclip,
    Pencil,
    Plus,
    RotateCcw,
    UploadCloud,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ComponentType, FormEvent, ReactNode, SVGProps } from 'react';
import InputError from '@/components/input-error';
import {
    IssueDialog,
    MeetingDialog,
    MinutesDialog,
    RequirementDialog,
    RiskDialog,
    TimelineDialog,
} from '@/components/projects/governance-dialogs';
import type { RequirementRecord } from '@/components/projects/governance-dialogs';
import { PhasePlanWorkspace } from '@/components/projects/phase-plan-workspace';
import { RequirementAnalysisPanel } from '@/components/projects/requirement-analysis-panel';
import { RequirementTaxonomyPanel } from '@/components/projects/requirement-taxonomy-panel';
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

type Project = {
    id: number | string;
    code?: string | null;
    name: string;
    client?:
        | string
        | {
              id?: number | string;
              name?: string;
              email?: string | null;
              phone?: string | null;
              address?: string | null;
              contacts?: Array<{
                  id: number | string;
                  name: string;
                  role?: string | null;
                  email?: string | null;
                  phone?: string | null;
                  is_primary?: boolean;
              }>;
          }
        | null;
    status?:
        | string
        | { id?: number | string; label?: string; color?: string }
        | null;
    statusColor?: string | null;
    priority?: string | null;
    progress?: number | null;
    health?: string | null;
    startDate?: string | null;
    endDate?: string | null;
    description?: string | null;
    client_id?: number | string | null;
    primary_contact_id?: number | string | null;
    manager_id?: number | string | null;
    status_id?: number | string | null;
    lock_version?: number;
    archived_at?: string | null;
    start_date?: string | null;
    end_date?: string | null;
    tasks?: Array<{
        id: number | string;
        code: string;
        title: string;
        priority?: string;
        due_at?: string | null;
        status?: { label?: string; color?: string; semantic?: string };
        assignee?: { name?: string } | null;
        can_update?: boolean;
        can_update_status?: boolean;
    }>;
    requirements?: Array<
        RequirementRecord & {
            archived_at?: string | null;
            status?: {
                id?: number | string;
                label?: string;
                color?: string;
            };
            owner?: { id: number | string; name: string } | null;
            can_update?: boolean;
            can_archive?: boolean;
            can_restore?: boolean;
        }
    >;
    timeline_entries?: Array<{
        id: number | string;
        lock_version: number;
        kind: string;
        title: string;
        starts_at: string;
        ends_at?: string | null;
        status?: string;
        archived_at?: string | null;
        owner_id?: number | string | null;
        note?: string | null;
        owner?: { id: number | string; name: string } | null;
        meeting?: {
            id: number | string;
            lock_version: number;
            archived_at?: string | null;
            organizer_id?: number | string | null;
            organizer?: { id: number | string; name: string } | null;
            location?: string | null;
            meeting_url?: string | null;
            agenda?: string | null;
            attendees?: Array<{
                id: number | string;
                name: string;
                pivot?: { attendance_status?: string };
            }>;
            minutes?: {
                lock_version: number;
                summary?: string | null;
                decisions?: string | null;
                action_items?: string | null;
                recorded_at?: string | null;
                file?: {
                    id: number | string;
                    original_name: string;
                    mime_type?: string;
                    size_bytes?: number;
                } | null;
            } | null;
        } | null;
    }>;
    risks?: Array<{
        id: number | string;
        lock_version: number;
        title: string;
        description?: string | null;
        probability: number;
        impact: number;
        status?: string;
        owner_id?: number | string | null;
        mitigation?: string | null;
        due_at?: string | null;
        archived_at?: string | null;
    }>;
    issues?: Array<{
        id: number | string;
        lock_version: number;
        title: string;
        description?: string | null;
        severity?: string;
        status?: string;
        owner_id?: number | string | null;
        due_at?: string | null;
        resolution?: string | null;
        archived_at?: string | null;
    }>;
    members?: Array<{
        id: number | string;
        name: string;
        email?: string;
        job_title?: string | null;
        pivot?: { project_role?: string; status?: string };
    }>;
    requirement_book?: {
        id: number | string;
        title: string;
        versions?: Array<{
            id: number | string;
            version_number: number;
            status?: string;
            uploaded_at?: string;
            is_current?: boolean;
        }>;
    } | null;
};

type RequirementStatus = {
    id: number | string;
    label: string;
};

type ProjectFile = {
    id: number;
    link_id: number;
    original_name: string;
    mime_type: string;
    extension: string;
    size_bytes: number;
    scan_status?: string;
    uploaded_at: string;
    uploader?: { id: number; name: string } | null;
    download_url: string | null;
    archived_at?: string | null;
    can_archive?: boolean;
    can_restore?: boolean;
    target: {
        type: 'project' | 'task' | 'requirement';
        id: number;
        code: string | null;
        label: string;
    };
};

type AttachmentTargetType = 'project' | 'task' | 'requirement';

type AttachmentTargetOption = {
    id: number;
    code: string;
    title: string;
};

type RequirementBookVersion = {
    id: number;
    title?: string | null;
    version_number: number;
    status: string;
    note?: string | null;
    is_current: boolean;
    lock_version: number;
    uploaded_at: string;
    uploader: { id: number; name: string };
    file: ProjectFile;
};

type RequirementBookData = {
    id: number | null;
    project_id: number | string;
    title: string | null;
    current_version_id: number | null;
    versions: RequirementBookVersion[];
};

type Activity = {
    id: number | string;
    action: string;
    subject_type: string;
    subject_id: number | string;
    created_at: string;
    actor?: string | null;
};

type PaginatedActivity = {
    data: Activity[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url?: string | null;
    next_page_url?: string | null;
};

type TabPagination = Omit<PaginatedActivity, 'data'>;

type ProjectMetrics = {
    progress?: number | null;
    openTasks?: number | null;
    overdueTasks?: number | null;
    requirements?: number | null;
    highRisks?: number | null;
    open_tasks?: number | null;
    overdue_tasks?: number | null;
    high_risks?: number | null;
    health?: string | null;
    phase_health?: string | null;
    progress_mode?: string | null;
    current_phase?: { id: number; title: string; progress: number } | null;
    next_milestone?: { id: number; title: string; starts_at: string } | null;
};

type ProjectShowProps = {
    project: Project;
    metrics?: ProjectMetrics | null;
    requirementStatuses?: RequirementStatus[];
    activity?: Activity[] | PaginatedActivity;
    tabPagination?: TabPagination | null;
    projectStatuses?: Array<{ id: number | string; label: string }>;
    clients?: Array<{
        id: number | string;
        name: string;
        contacts?: Array<{ id: number | string; name: string }>;
    }>;
    availableMembers?: Array<{ id: number | string; name: string }>;
    canManage?: boolean;
    canArchive?: boolean;
    canRestore?: boolean;
    canCreateTask?: boolean;
    canUploadFile?: boolean;
    governanceArchivedMode?: boolean;
};

type IconComponent = ComponentType<SVGProps<SVGSVGElement>>;

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
    healthy: 'مستقر',
    attention: 'يحتاج انتباهاً',
    danger: 'مهدد',
};

function healthLabel(value?: string | null) {
    return value ? (healthLabels[value] ?? value) : 'غير محسوبة';
}

const tabs: Array<{
    id: string;
    label: string;
    icon: IconComponent;
    description: string;
}> = [
    {
        id: 'overview',
        label: 'نظرة عامة',
        icon: CircleDot,
        description: 'سيظهر ملخص المشروع وقراراته الأخيرة هنا.',
    },
    {
        id: 'requirements',
        label: 'المتطلبات',
        icon: ClipboardList,
        description: 'لم تُضف متطلبات أو كراسات متطلبات لهذا المشروع بعد.',
    },
    {
        id: 'tasks',
        label: 'المهام',
        icon: ListChecks,
        description: 'لا توجد مهام مرتبطة بالمشروع حتى الآن.',
    },
    {
        id: 'timeline',
        label: 'الجدول الزمني',
        icon: CalendarRange,
        description: 'أضف مراحل ومواعيد لبناء الخط الزمني للمشروع.',
    },
    {
        id: 'meetings',
        label: 'الاجتماعات والمحاضر',
        icon: CalendarCheck2,
        description: 'لا توجد اجتماعات مجدولة أو محاضر محفوظة بعد.',
    },
    {
        id: 'risks',
        label: 'المخاطر',
        icon: ShieldAlert,
        description: 'لا توجد مخاطر مسجلة لهذا المشروع.',
    },
    {
        id: 'issues',
        label: 'المشكلات',
        icon: AlertTriangle,
        description: 'لا توجد مشكلات مفتوحة في المشروع.',
    },
    {
        id: 'team',
        label: 'الفريق',
        icon: Users,
        description: 'لم يُضف أعضاء إلى فريق المشروع بعد.',
    },
    {
        id: 'documents',
        label: 'الوثائق',
        icon: Paperclip,
        description: 'لا توجد مرفقات أو محاضر أو مستندات مرتبطة بعد.',
    },
    {
        id: 'client',
        label: 'العميل',
        icon: Building2,
        description: 'لم يُربط المشروع بعميل أو جهة اتصال بعد.',
    },
    {
        id: 'activity',
        label: 'النشاط',
        icon: History,
        description: 'لا توجد أحداث مسجلة لهذا المشروع بعد.',
    },
];

function formatDate(value?: string | null) {
    if (!value) {
        return 'غير محدد';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

function toDateInput(value?: string | null) {
    return value ? value.slice(0, 10) : '';
}

function formatMetric(value?: number | null, suffix = '') {
    return typeof value === 'number'
        ? `${numberFormatter.format(value)}${suffix}`
        : '—';
}

function clientName(client: Project['client']) {
    if (!client) {
        return 'دون عميل';
    }

    return typeof client === 'string' ? client : client.name || 'دون عميل';
}

function csrfToken() {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

async function projectJson<T>(url: string, init?: RequestInit): Promise<T> {
    const isFormData = init?.body instanceof FormData;
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
            ...init?.headers,
        },
    });
    const payload = (await response.json().catch(() => null)) as {
        data?: T;
        message?: string;
        errors?: Record<string, string[]>;
    } | null;

    if (!response.ok) {
        const validation = payload?.errors
            ? Object.values(payload.errors).flat()[0]
            : undefined;

        throw new Error(
            validation || payload?.message || 'تعذر إتمام العملية.',
        );
    }

    return (payload?.data ?? payload) as T;
}

function formatFileSize(value: number) {
    return value < 1024 * 1024
        ? `${Math.ceil(value / 1024)} KB`
        : `${(value / 1024 / 1024).toFixed(1)} MB`;
}

function attachmentTargetLabel(file: ProjectFile) {
    if (file.target.type === 'task') {
        return `مهمة: ${file.target.code ?? ''} — ${file.target.label}`;
    }

    if (file.target.type === 'requirement') {
        return `متطلب: ${file.target.code ?? ''} — ${file.target.label}`;
    }

    return 'المشروع';
}

function EmptyProjectPanel({
    icon: Icon,
    label,
    title,
    description,
    action,
}: {
    icon: IconComponent;
    label: string;
    title: string;
    description: string;
    action?: React.ReactNode;
}) {
    return (
        <div className="project-panel-empty">
            <div className="cloudtech-empty-icon">
                <Icon aria-hidden="true" />
            </div>
            <p className="cloudtech-empty-kicker">{label}</p>
            <h2 id="project-panel-title">{title}</h2>
            <p>{description}</p>
            {action}
        </div>
    );
}

function EditProjectDialog({
    project,
    statuses,
    clients,
    members,
}: {
    project: Project;
    statuses: Array<{ id: number | string; label: string }>;
    clients: Array<{
        id: number | string;
        name: string;
        contacts?: Array<{ id: number | string; name: string }>;
    }>;
    members: Array<{ id: number | string; name: string }>;
}) {
    const initialClientId = String(
        project.client_id ||
            (typeof project.client === 'object'
                ? project.client?.id || ''
                : ''),
    );
    const initialContactId = String(project.primary_contact_id || '');
    const [clientId, setClientId] = useState(initialClientId);
    const [contactId, setContactId] = useState(initialContactId);
    const {
        allowNextNavigation,
        closeAfterSuccess,
        markDirty,
        onOpenChange,
        open,
    } = useUnsavedDialog(
        false,
        'لديك تغييرات غير محفوظة في المشروع. هل تريد تجاهلها؟',
    );
    const contacts =
        clients.find((client) => String(client.id) === clientId)?.contacts ??
        [];
    const selectedMemberRoles = new Map(
        (project.members ?? []).map((member) => [
            String(member.id),
            member.pivot?.project_role || 'member',
        ]),
    );
    const statusId =
        project.status_id ||
        (typeof project.status === 'object' ? project.status?.id : '');

    function changeOpen(nextOpen: boolean) {
        if (onOpenChange(nextOpen)) {
            setClientId(initialClientId);
            setContactId(initialContactId);
        }
    }

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>
                <button type="button" className="project-secondary-action">
                    <Pencil aria-hidden="true" />
                    تعديل المشروع
                </button>
            </DialogTrigger>
            <DialogContent className="cloudtech-dialog" dir="rtl">
                <DialogHeader className="text-start">
                    <p className="cloudtech-eyebrow">إدارة المشروع</p>
                    <DialogTitle>تعديل {project.name}</DialogTitle>
                    <DialogDescription>
                        حدّث البيانات والفريق والجدول دون التأثير على المهام
                        المسجلة.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={`/projects/${project.id}`}
                    method="put"
                    className="cloudtech-form"
                    onChange={markDirty}
                    onBefore={allowNextNavigation}
                    onSuccess={closeAfterSuccess}
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="lock_version"
                                value={project.lock_version ?? 1}
                            />
                            <div className="cloudtech-form-grid two-columns">
                                <label>
                                    <span>رمز المشروع</span>
                                    <input
                                        name="code"
                                        defaultValue={project.code || ''}
                                        required
                                        dir="ltr"
                                    />
                                    <InputError message={errors.code} />
                                </label>
                                <label>
                                    <span>اسم المشروع</span>
                                    <input
                                        name="name"
                                        defaultValue={project.name}
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </label>
                            </div>
                            <label>
                                <span>الوصف</span>
                                <textarea
                                    name="description"
                                    rows={3}
                                    defaultValue={project.description || ''}
                                />
                                <InputError message={errors.description} />
                            </label>
                            <div className="cloudtech-form-grid two-columns">
                                <label>
                                    <span>العميل</span>
                                    <select
                                        name="client_id"
                                        value={clientId}
                                        onChange={(event) => {
                                            setClientId(event.target.value);
                                            setContactId('');
                                        }}
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
                                        value={contactId}
                                        onChange={(event) =>
                                            setContactId(event.target.value)
                                        }
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
                                    <select
                                        name="manager_id"
                                        defaultValue={String(
                                            project.manager_id || '',
                                        )}
                                    >
                                        <option value="">غير محدد</option>
                                        {members.map((member) => (
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
                                <label>
                                    <span>الحالة</span>
                                    <select
                                        name="status_id"
                                        defaultValue={String(statusId || '')}
                                        required
                                    >
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
                                        defaultValue={
                                            project.priority || 'medium'
                                        }
                                        required
                                    >
                                        {Object.entries(priorityLabels).map(
                                            ([value, label]) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                    <InputError message={errors.priority} />
                                </label>
                                <label>
                                    <span>تاريخ البداية</span>
                                    <input
                                        name="start_date"
                                        type="date"
                                        defaultValue={toDateInput(
                                            project.start_date ??
                                                project.startDate,
                                        )}
                                    />
                                    <InputError message={errors.start_date} />
                                </label>
                                <label>
                                    <span>تاريخ النهاية</span>
                                    <input
                                        name="end_date"
                                        type="date"
                                        defaultValue={toDateInput(
                                            project.end_date ?? project.endDate,
                                        )}
                                    />
                                    <InputError message={errors.end_date} />
                                </label>
                            </div>
                            <fieldset className="project-member-picker">
                                <legend>أعضاء المشروع</legend>
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
                                                defaultValue={
                                                    selectedMemberRoles.get(
                                                        String(member.id),
                                                    ) || ''
                                                }
                                                aria-label={`دور ${member.name}`}
                                            >
                                                <option value="">
                                                    غير مضاف
                                                </option>
                                                <option value="manager">
                                                    مدير
                                                </option>
                                                <option value="member">
                                                    عضو
                                                </option>
                                                <option value="viewer">
                                                    مشاهد
                                                </option>
                                            </select>
                                        </label>
                                    ))}
                                </div>
                                <InputError
                                    message={
                                        errors.members || errors.member_ids
                                    }
                                />
                            </fieldset>
                            <div className="project-wizard-actions">
                                <button
                                    type="submit"
                                    className="cloudtech-primary-action"
                                    disabled={processing}
                                >
                                    {processing
                                        ? 'جارٍ الحفظ…'
                                        : 'حفظ التعديلات'}
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function GovernanceViewToggle({
    projectId,
    tab,
    archived,
}: {
    projectId: number | string;
    tab: string;
    archived: boolean;
}) {
    return (
        <Link
            className="project-panel-action"
            href={
                archived
                    ? `/projects/${projectId}?tab=${tab}`
                    : `/projects/${projectId}?tab=${tab}&archived=1`
            }
            preserveScroll
        >
            {archived ? (
                <CheckCircle2 aria-hidden="true" />
            ) : (
                <Archive aria-hidden="true" />
            )}
            {archived ? 'عرض السجلات النشطة' : 'عرض الأرشيف'}
        </Link>
    );
}

function GovernanceRecordActions({
    archived,
    archiveAction,
    restoreAction,
    recordLabel,
    edit,
    lockVersion,
}: {
    archived: boolean;
    archiveAction: string;
    restoreAction: string;
    recordLabel: string;
    edit?: ReactNode;
    lockVersion?: number;
}) {
    return (
        <div className="governance-record-actions">
            {!archived && edit}
            <Form
                action={archived ? restoreAction : archiveAction}
                method="post"
                onBefore={() =>
                    archived ||
                    window.confirm(
                        `هل تريد أرشفة «${recordLabel}»؟ سيبقى السجل محفوظاً ويمكن استعادته لاحقاً.`,
                    )
                }
            >
                {({ processing }) => (
                    <>
                        {lockVersion !== undefined && (
                            <input
                                type="hidden"
                                name="lock_version"
                                value={lockVersion}
                            />
                        )}
                        <button
                            type="submit"
                            className={archived ? undefined : 'is-danger'}
                            disabled={processing}
                            aria-label={`${archived ? 'استعادة' : 'أرشفة'} ${recordLabel}`}
                        >
                            {archived ? (
                                <RotateCcw aria-hidden="true" />
                            ) : (
                                <Archive aria-hidden="true" />
                            )}
                            {archived ? 'استعادة' : 'أرشفة'}
                        </button>
                    </>
                )}
            </Form>
        </div>
    );
}

export default function ProjectShow({
    project,
    metrics,
    requirementStatuses = [],
    activity = [],
    tabPagination = null,
    projectStatuses = [],
    clients = [],
    availableMembers = [],
    canManage = false,
    canArchive = false,
    canRestore = false,
    canCreateTask = false,
    canUploadFile = false,
    governanceArchivedMode = false,
}: ProjectShowProps) {
    const { url } = usePage();
    const activeTab =
        new URL(url, 'http://localhost').searchParams.get('tab') || 'overview';
    const currentTab = tabs.find((tab) => tab.id === activeTab) ?? tabs[0];
    const tabListRef = useRef<HTMLElement>(null);
    const activeTabRef = useRef<HTMLAnchorElement>(null);

    useEffect(() => {
        const frame = window.requestAnimationFrame(() => {
            const tabList = tabListRef.current;
            const activeTab = activeTabRef.current;

            if (!tabList || !activeTab) {
                return;
            }

            const listRect = tabList.getBoundingClientRect();
            const tabRect = activeTab.getBoundingClientRect();
            const edgePadding = 8;
            const delta =
                tabRect.left < listRect.left
                    ? tabRect.left - listRect.left - edgePadding
                    : tabRect.right > listRect.right
                      ? tabRect.right - listRect.right + edgePadding
                      : 0;

            if (delta !== 0) {
                tabList.scrollBy({ left: delta });
            }
        });

        return () => window.cancelAnimationFrame(frame);
    }, [currentTab.id]);
    const activityRows = Array.isArray(activity) ? activity : activity.data;
    const activityPagination = Array.isArray(activity) ? null : activity;
    const isGovernanceTab = [
        'requirements',
        'timeline',
        'meetings',
        'risks',
        'issues',
    ].includes(currentTab.id);
    const [documentLoading, setDocumentLoading] = useState(false);
    const [documentBusy, setDocumentBusy] = useState(false);
    const [documentError, setDocumentError] = useState('');
    const [documentNotice, setDocumentNotice] = useState('');
    const [projectFiles, setProjectFiles] = useState<ProjectFile[]>([]);
    const [attachmentTargetType, setAttachmentTargetType] =
        useState<AttachmentTargetType>('project');
    const [attachmentTargetId, setAttachmentTargetId] = useState('');
    const [attachmentTargetQuery, setAttachmentTargetQuery] = useState('');
    const [attachmentTargets, setAttachmentTargets] = useState<
        AttachmentTargetOption[]
    >([]);
    const [attachmentTargetsLoading, setAttachmentTargetsLoading] =
        useState(false);
    const [attachmentTargetError, setAttachmentTargetError] = useState('');
    const attachmentTargetReady =
        attachmentTargetType === 'project' || attachmentTargetId !== '';
    const activeProjectFiles = projectFiles.filter((file) => !file.archived_at);
    const archivedProjectFiles = projectFiles.filter((file) =>
        Boolean(file.archived_at),
    );
    const [requirementBook, setRequirementBook] =
        useState<RequirementBookData | null>(null);
    const progress = Math.min(
        100,
        Math.max(0, metrics?.progress ?? project.progress ?? 0),
    );
    const hasProgress =
        typeof metrics?.progress === 'number' ||
        typeof project.progress === 'number';
    const projectStatus =
        typeof project.status === 'string'
            ? project.status
            : (project.status as { label?: string } | null)?.label;

    const loadDocuments = useCallback(async () => {
        setDocumentLoading(true);
        setDocumentError('');

        try {
            const [book, files] = await Promise.all([
                projectJson<RequirementBookData>(
                    `/projects/${project.id}/requirement-book`,
                ),
                projectJson<ProjectFile[]>(
                    `/projects/${project.id}/files?per_page=50&include_archived=1`,
                ),
            ]);
            setRequirementBook(book);
            setProjectFiles(files);
        } catch (requestError) {
            setDocumentError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر تحميل مستندات المشروع.',
            );
        } finally {
            setDocumentLoading(false);
        }
    }, [project.id]);

    useEffect(() => {
        if (currentTab.id !== 'documents') {
            return;
        }

        let cancelled = false;
        void Promise.all([
            projectJson<RequirementBookData>(
                `/projects/${project.id}/requirement-book`,
            ),
            projectJson<ProjectFile[]>(
                `/projects/${project.id}/files?per_page=50&include_archived=1`,
            ),
        ])
            .then(([book, files]) => {
                if (!cancelled) {
                    setRequirementBook(book);
                    setProjectFiles(files);
                }
            })
            .catch((requestError: unknown) => {
                if (!cancelled) {
                    setDocumentError(
                        requestError instanceof Error
                            ? requestError.message
                            : 'تعذر تحميل مستندات المشروع.',
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setDocumentLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [currentTab.id, project.id]);

    useEffect(() => {
        if (
            currentTab.id !== 'documents' ||
            attachmentTargetType === 'project'
        ) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setAttachmentTargetsLoading(true);
            setAttachmentTargetError('');
            const params = new URLSearchParams({
                type: attachmentTargetType,
            });

            if (attachmentTargetQuery.trim() !== '') {
                params.set('q', attachmentTargetQuery.trim());
            }

            void projectJson<AttachmentTargetOption[]>(
                `/projects/${project.id}/file-targets?${params.toString()}`,
                { signal: controller.signal },
            )
                .then((targets) => {
                    setAttachmentTargets(targets);
                    setAttachmentTargetId((current) =>
                        targets.some((target) => String(target.id) === current)
                            ? current
                            : '',
                    );
                })
                .catch((requestError: unknown) => {
                    if (
                        requestError instanceof DOMException &&
                        requestError.name === 'AbortError'
                    ) {
                        return;
                    }

                    setAttachmentTargets([]);
                    setAttachmentTargetId('');
                    setAttachmentTargetError(
                        requestError instanceof Error
                            ? requestError.message
                            : 'تعذر تحميل أهداف الربط.',
                    );
                })
                .finally(() => {
                    if (!controller.signal.aborted) {
                        setAttachmentTargetsLoading(false);
                    }
                });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [
        attachmentTargetQuery,
        attachmentTargetType,
        currentTab.id,
        project.id,
    ]);

    async function uploadProjectFile(file: File) {
        if (!attachmentTargetReady) {
            setAttachmentTargetError('اختر مهمة أو متطلباً قبل رفع المرفق.');

            return;
        }

        setDocumentBusy(true);
        setDocumentError('');
        setDocumentNotice('');
        const body = new FormData();
        body.append('file', file);
        body.append('target_type', attachmentTargetType);

        if (attachmentTargetType !== 'project') {
            body.append('target_id', attachmentTargetId);
        }

        try {
            await projectJson<ProjectFile>(`/projects/${project.id}/files`, {
                method: 'POST',
                body,
            });
            setDocumentNotice('تم رفع المرفق وحفظه ضمن المشروع.');
            await loadDocuments();
        } catch (requestError) {
            setDocumentError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر رفع المرفق.',
            );
        } finally {
            setDocumentBusy(false);
        }
    }

    async function archiveProjectFile(file: ProjectFile) {
        if (
            !window.confirm(
                `أرشفة المرفق ${file.original_name} من هذا المشروع؟ سيبقى السجل محفوظاً.`,
            )
        ) {
            return;
        }

        setDocumentBusy(true);
        setDocumentError('');
        setDocumentNotice('');

        try {
            await projectJson(
                `/projects/${project.id}/files/${file.id}/links/${file.link_id}/archive`,
                { method: 'POST', body: JSON.stringify({}) },
            );
            setDocumentNotice('تمت أرشفة المرفق دون حذفه نهائياً.');
            await loadDocuments();
        } catch (requestError) {
            setDocumentError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذرت أرشفة المرفق.',
            );
        } finally {
            setDocumentBusy(false);
        }
    }

    async function restoreProjectFile(file: ProjectFile) {
        setDocumentBusy(true);
        setDocumentError('');
        setDocumentNotice('');

        try {
            await projectJson(
                `/projects/${project.id}/files/${file.id}/links/${file.link_id}/restore`,
                { method: 'POST', body: JSON.stringify({}) },
            );
            setDocumentNotice('تمت استعادة المرفق وأصبح متاحاً للتنزيل.');
            await loadDocuments();
        } catch (requestError) {
            setDocumentError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذرت استعادة المرفق.',
            );
        } finally {
            setDocumentBusy(false);
        }
    }

    async function uploadRequirementBook(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = event.currentTarget;
        setDocumentBusy(true);
        setDocumentError('');
        setDocumentNotice('');
        const body = new FormData(form);

        try {
            await projectJson<RequirementBookVersion>(
                `/projects/${project.id}/requirement-book/versions`,
                { method: 'POST', body },
            );
            form.reset();
            setDocumentNotice('تم رفع إصدار جديد من كراسة المتطلبات.');
            await loadDocuments();
        } catch (requestError) {
            setDocumentError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر رفع كراسة المتطلبات.',
            );
        } finally {
            setDocumentBusy(false);
        }
    }

    async function actOnRequirementBookVersion(
        version: RequirementBookVersion,
        action: 'make-current' | 'archive',
    ) {
        if (
            action === 'archive' &&
            !window.confirm(
                'ستؤرشف هذه النسخة دون حذف ملفها. هل تريد المتابعة؟',
            )
        ) {
            return;
        }

        setDocumentBusy(true);
        setDocumentError('');
        setDocumentNotice('');

        try {
            await projectJson<RequirementBookVersion>(
                `/projects/${project.id}/requirement-book/versions/${version.id}/${action}`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        lock_version: version.lock_version,
                    }),
                },
            );
            setDocumentNotice(
                action === 'make-current'
                    ? 'تم تعيين الإصدار الحالي للكراسة.'
                    : 'تمت أرشفة الإصدار دون حذف ملفه.',
            );
            await loadDocuments();
        } catch (requestError) {
            setDocumentError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر تحديث إصدار الكراسة.',
            );
        } finally {
            setDocumentBusy(false);
        }
    }
    const members = project.members ?? [];
    const meetings = (project.timeline_entries ?? []).filter(
        (entry) => entry.kind === 'meeting' && entry.meeting,
    );

    const renderPanel = () => {
        if (currentTab.id === 'overview') {
            return (
                <div className="project-overview-grid">
                    <article>
                        <p className="cloudtech-eyebrow">ملخص تنفيذي</p>
                        <h2 id="project-panel-title">الوضع الحالي</h2>
                        <p>
                            {project.description ||
                                'لم يُضف وصف تنفيذي للمشروع بعد.'}
                        </p>
                        <dl>
                            <div>
                                <dt>التقدم</dt>
                                <dd>{formatMetric(progress, '٪')}</dd>
                            </div>
                            <div>
                                <dt>المهام المفتوحة</dt>
                                <dd>{formatMetric(metrics?.open_tasks)}</dd>
                            </div>
                            <div>
                                <dt>المتطلبات</dt>
                                <dd>{formatMetric(metrics?.requirements)}</dd>
                            </div>
                            <div>
                                <dt>صحة المشروع</dt>
                                <dd>{healthLabel(metrics?.health)}</dd>
                            </div>
                        </dl>
                    </article>
                    <article>
                        <p className="cloudtech-eyebrow">الخطوة القادمة</p>
                        <h2>أقرب موعد مسجل</h2>
                        {project.timeline_entries?.[0] ? (
                            <>
                                <strong>
                                    {project.timeline_entries[0].title}
                                </strong>
                                <time
                                    dateTime={
                                        project.timeline_entries[0].starts_at
                                    }
                                >
                                    {formatDate(
                                        project.timeline_entries[0].starts_at,
                                    )}
                                </time>
                            </>
                        ) : (
                            <p>لا توجد مرحلة أو اجتماع قادم.</p>
                        )}
                    </article>
                </div>
            );
        }

        if (currentTab.id === 'tasks' && (project.tasks?.length ?? 0) > 0) {
            return (
                <div className="project-record-list">
                    <header>
                        <h2 id="project-panel-title">مهام المشروع</h2>
                        {canCreateTask && (
                            <Link href={`/tasks/create?project=${project.id}`}>
                                <Plus aria-hidden="true" />
                                إضافة مهمة
                            </Link>
                        )}
                    </header>
                    <ul>
                        {project.tasks?.map((task) => (
                            <li key={task.id}>
                                {task.can_update ? (
                                    <Link href={`/tasks/${task.id}/edit`}>
                                        <span
                                            className="dashboard-code"
                                            dir="ltr"
                                        >
                                            {task.code}
                                        </span>
                                        <strong>{task.title}</strong>
                                        <small>
                                            {task.assignee?.name || 'غير مسندة'}{' '}
                                            · {formatDate(task.due_at)}
                                        </small>
                                    </Link>
                                ) : (
                                    <div>
                                        <span
                                            className="dashboard-code"
                                            dir="ltr"
                                        >
                                            {task.code}
                                        </span>
                                        <strong>{task.title}</strong>
                                        <small>
                                            {task.assignee?.name || 'غير مسندة'}{' '}
                                            · {formatDate(task.due_at)}
                                        </small>
                                    </div>
                                )}
                                <span
                                    className="table-status"
                                    style={
                                        {
                                            '--status-color':
                                                task.status?.color || '#406386',
                                        } as React.CSSProperties
                                    }
                                >
                                    {task.status?.label || 'دون حالة'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            );
        }

        if (currentTab.id === 'requirements') {
            return (
                <div className="project-record-list w-full">
                    <header>
                        <h2 id="project-panel-title">متطلبات المشروع</h2>
                        <div className="project-panel-actions">
                            {!governanceArchivedMode && canManage && (
                                <RequirementDialog
                                    projectId={project.id}
                                    projectName={project.name}
                                    members={members}
                                    statuses={requirementStatuses}
                                />
                            )}
                            <GovernanceViewToggle
                                projectId={project.id}
                                tab="requirements"
                                archived={governanceArchivedMode}
                            />
                        </div>
                    </header>
                    {!governanceArchivedMode && (
                        <RequirementTaxonomyPanel
                            projectId={project.id}
                            canManage={canManage}
                        />
                    )}
                    <ul aria-live="polite">
                        {project.requirements?.map((requirement) => (
                            <li
                                key={requirement.id}
                                id={`requirement-${requirement.id}`}
                            >
                                <div>
                                    <span className="dashboard-code" dir="ltr">
                                        {requirement.code}
                                    </span>
                                    <strong>{requirement.title}</strong>
                                    <small>
                                        {requirement.owner?.name ||
                                            'دون مالك محدد'}{' '}
                                        · أولوية{' '}
                                        {priorityLabels[
                                            requirement.priority || 'medium'
                                        ] || requirement.priority}
                                    </small>
                                </div>
                                <div className="governance-record-meta">
                                    <span
                                        className="table-status"
                                        style={
                                            {
                                                '--status-color':
                                                    requirement.status?.color ||
                                                    '#406386',
                                            } as React.CSSProperties
                                        }
                                    >
                                        {requirement.status?.label ||
                                            'دون حالة'}
                                    </span>
                                    {(requirement.can_archive ||
                                        requirement.can_restore) && (
                                        <GovernanceRecordActions
                                            archived={Boolean(
                                                requirement.archived_at,
                                            )}
                                            recordLabel={requirement.title}
                                            archiveAction={`/projects/${project.id}/requirements/${requirement.id}/archive`}
                                            restoreAction={`/projects/${project.id}/requirements/${requirement.id}/restore`}
                                            lockVersion={
                                                requirement.lock_version
                                            }
                                            edit={
                                                requirement.can_update ? (
                                                    <RequirementDialog
                                                        projectId={project.id}
                                                        projectName={
                                                            project.name
                                                        }
                                                        members={members}
                                                        statuses={
                                                            requirementStatuses
                                                        }
                                                        requirement={
                                                            requirement
                                                        }
                                                    />
                                                ) : undefined
                                            }
                                        />
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            );
        }

        if (currentTab.id === 'timeline') {
            return (
                <div className="project-timeline-list">
                    {!governanceArchivedMode && (
                        <PhasePlanWorkspace
                            projectId={project.id}
                            canManage={canManage}
                        />
                    )}
                    <header>
                        <h2 id="project-panel-title">الجدول الزمني للمشروع</h2>
                        {canManage && (
                            <div className="project-panel-actions">
                                {!governanceArchivedMode && (
                                    <>
                                        <TimelineDialog
                                            projectId={project.id}
                                            projectName={project.name}
                                            members={members}
                                        />
                                        <MeetingDialog
                                            projectId={project.id}
                                            projectName={project.name}
                                            members={members}
                                        />
                                    </>
                                )}
                                <GovernanceViewToggle
                                    projectId={project.id}
                                    tab="timeline"
                                    archived={governanceArchivedMode}
                                />
                            </div>
                        )}
                    </header>
                    <ol>
                        {project.timeline_entries
                            ?.filter(
                                (entry) =>
                                    !['phase', 'milestone'].includes(
                                        entry.kind,
                                    ),
                            )
                            .map((entry) => (
                                <li key={entry.id}>
                                    <time dateTime={entry.starts_at}>
                                        {formatDate(entry.starts_at)}
                                    </time>
                                    <div>
                                        <span>
                                            {entry.kind === 'meeting'
                                                ? 'اجتماع'
                                                : 'مرحلة'}
                                        </span>
                                        <strong>{entry.title}</strong>
                                        <small>
                                            {entry.status || 'مخطط'}
                                            {entry.ends_at
                                                ? ` · حتى ${formatDate(entry.ends_at)}`
                                                : ''}
                                        </small>
                                    </div>
                                    {canManage && (
                                        <GovernanceRecordActions
                                            archived={Boolean(
                                                entry.archived_at,
                                            )}
                                            lockVersion={
                                                entry.kind === 'meeting' &&
                                                entry.meeting
                                                    ? entry.meeting.lock_version
                                                    : entry.lock_version
                                            }
                                            recordLabel={entry.title}
                                            archiveAction={
                                                entry.kind === 'meeting' &&
                                                entry.meeting
                                                    ? `/projects/${project.id}/meetings/${entry.meeting.id}/archive`
                                                    : `/projects/${project.id}/timeline-entries/${entry.id}/archive`
                                            }
                                            restoreAction={
                                                entry.kind === 'meeting' &&
                                                entry.meeting
                                                    ? `/projects/${project.id}/meetings/${entry.meeting.id}/restore`
                                                    : `/projects/${project.id}/timeline-entries/${entry.id}/restore`
                                            }
                                            edit={
                                                entry.kind === 'meeting' &&
                                                entry.meeting ? (
                                                    <MeetingDialog
                                                        projectId={project.id}
                                                        projectName={
                                                            project.name
                                                        }
                                                        members={members}
                                                        meeting={{
                                                            ...entry.meeting,
                                                            title: entry.title,
                                                            starts_at:
                                                                entry.starts_at,
                                                            ends_at:
                                                                entry.ends_at,
                                                            status: entry.status,
                                                            organizer_id:
                                                                entry.meeting
                                                                    .organizer_id ??
                                                                entry.meeting
                                                                    .organizer
                                                                    ?.id,
                                                        }}
                                                    />
                                                ) : (
                                                    <TimelineDialog
                                                        projectId={project.id}
                                                        projectName={
                                                            project.name
                                                        }
                                                        members={members}
                                                        entry={entry}
                                                    />
                                                )
                                            }
                                        />
                                    )}
                                </li>
                            ))}
                    </ol>
                </div>
            );
        }

        if (currentTab.id === 'meetings' && meetings.length > 0) {
            return (
                <div className="project-record-list project-meetings-list">
                    <header>
                        <h2 id="project-panel-title">الاجتماعات ومحاضرها</h2>
                        {canManage && (
                            <div className="project-panel-actions">
                                {!governanceArchivedMode && (
                                    <MeetingDialog
                                        projectId={project.id}
                                        projectName={project.name}
                                        members={members}
                                    />
                                )}
                                <GovernanceViewToggle
                                    projectId={project.id}
                                    tab="meetings"
                                    archived={governanceArchivedMode}
                                />
                            </div>
                        )}
                    </header>
                    <ul>
                        {meetings.map((entry) => {
                            const meeting = entry.meeting;

                            if (!meeting) {
                                return null;
                            }

                            return (
                                <li
                                    key={entry.id}
                                    className="project-meeting-record"
                                >
                                    <div>
                                        <span className="meeting-kind">
                                            اجتماع
                                        </span>
                                        <strong>{entry.title}</strong>
                                        <small>
                                            <time dateTime={entry.starts_at}>
                                                {formatDate(entry.starts_at)}
                                            </time>
                                            {' · '}
                                            {meeting.location ||
                                                'دون مكان محدد'}
                                            {' · '}
                                            {meeting.attendees?.length ??
                                                0}{' '}
                                            حضور
                                        </small>
                                        {meeting.minutes?.summary && (
                                            <p className="meeting-minutes-summary">
                                                {meeting.minutes.summary}
                                            </p>
                                        )}
                                        {meeting.minutes?.file &&
                                            !governanceArchivedMode && (
                                                <a
                                                    className="meeting-minutes-file"
                                                    href={`/files/${meeting.minutes.file.id}/download`}
                                                >
                                                    <Paperclip aria-hidden="true" />
                                                    تنزيل ملف المحضر:{' '}
                                                    <bdi>
                                                        {
                                                            meeting.minutes.file
                                                                .original_name
                                                        }
                                                    </bdi>
                                                    {typeof meeting.minutes.file
                                                        .size_bytes ===
                                                        'number' && (
                                                        <small>
                                                            {formatFileSize(
                                                                meeting.minutes
                                                                    .file
                                                                    .size_bytes,
                                                            )}
                                                        </small>
                                                    )}
                                                </a>
                                            )}
                                        {meeting.minutes?.file &&
                                            governanceArchivedMode && (
                                                <span className="meeting-minutes-file is-archived">
                                                    <Paperclip aria-hidden="true" />
                                                    ملف المحضر محفوظ؛ استعد
                                                    الاجتماع لتنزيله.
                                                </span>
                                            )}
                                    </div>
                                    {canManage && (
                                        <div className="governance-record-actions">
                                            {!governanceArchivedMode && (
                                                <MinutesDialog
                                                    projectId={project.id}
                                                    meeting={{
                                                        id: meeting.id,
                                                        title: entry.title,
                                                        minutes:
                                                            meeting.minutes,
                                                    }}
                                                />
                                            )}
                                            <GovernanceRecordActions
                                                archived={Boolean(
                                                    meeting.archived_at,
                                                )}
                                                lockVersion={
                                                    meeting.lock_version
                                                }
                                                recordLabel={entry.title}
                                                archiveAction={`/projects/${project.id}/meetings/${meeting.id}/archive`}
                                                restoreAction={`/projects/${project.id}/meetings/${meeting.id}/restore`}
                                                edit={
                                                    <MeetingDialog
                                                        projectId={project.id}
                                                        projectName={
                                                            project.name
                                                        }
                                                        members={members}
                                                        meeting={{
                                                            ...meeting,
                                                            title: entry.title,
                                                            starts_at:
                                                                entry.starts_at,
                                                            ends_at:
                                                                entry.ends_at,
                                                            status: entry.status,
                                                            organizer_id:
                                                                meeting.organizer_id ??
                                                                meeting
                                                                    .organizer
                                                                    ?.id,
                                                        }}
                                                    />
                                                }
                                            />
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            );
        }

        if (currentTab.id === 'risks' && (project.risks?.length ?? 0) > 0) {
            return (
                <div className="project-record-list">
                    <header>
                        <h2 id="project-panel-title">سجل المخاطر</h2>
                        {canManage && (
                            <div className="project-panel-actions">
                                {!governanceArchivedMode && (
                                    <RiskDialog
                                        projectId={project.id}
                                        projectName={project.name}
                                        members={members}
                                    />
                                )}
                                <GovernanceViewToggle
                                    projectId={project.id}
                                    tab="risks"
                                    archived={governanceArchivedMode}
                                />
                            </div>
                        )}
                    </header>
                    <ul>
                        {project.risks?.map((risk) => (
                            <li key={risk.id}>
                                <div>
                                    <strong>{risk.title}</strong>
                                    <small>
                                        {risk.mitigation ||
                                            'لم تُسجل خطة استجابة'}
                                    </small>
                                </div>
                                <div className="governance-record-meta">
                                    <span className="risk-score">
                                        {risk.probability * risk.impact}
                                    </span>
                                    {canManage && (
                                        <GovernanceRecordActions
                                            archived={Boolean(risk.archived_at)}
                                            lockVersion={risk.lock_version}
                                            recordLabel={risk.title}
                                            archiveAction={`/projects/${project.id}/risks/${risk.id}/archive`}
                                            restoreAction={`/projects/${project.id}/risks/${risk.id}/restore`}
                                            edit={
                                                <RiskDialog
                                                    projectId={project.id}
                                                    projectName={project.name}
                                                    members={members}
                                                    risk={risk}
                                                />
                                            }
                                        />
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            );
        }

        if (currentTab.id === 'issues' && (project.issues?.length ?? 0) > 0) {
            return (
                <div className="project-record-list">
                    <header>
                        <h2 id="project-panel-title">المشكلات المفتوحة</h2>
                        {canManage && (
                            <div className="project-panel-actions">
                                {!governanceArchivedMode && (
                                    <IssueDialog
                                        projectId={project.id}
                                        projectName={project.name}
                                        members={members}
                                    />
                                )}
                                <GovernanceViewToggle
                                    projectId={project.id}
                                    tab="issues"
                                    archived={governanceArchivedMode}
                                />
                            </div>
                        )}
                    </header>
                    <ul>
                        {project.issues?.map((issue) => (
                            <li key={issue.id}>
                                <div>
                                    <strong>{issue.title}</strong>
                                    <small>
                                        {issue.resolution ||
                                            `الشدة: ${issue.severity || 'متوسطة'}`}
                                    </small>
                                </div>
                                <div className="governance-record-meta">
                                    <span>{issue.status || 'مفتوحة'}</span>
                                    {canManage && (
                                        <GovernanceRecordActions
                                            archived={Boolean(
                                                issue.archived_at,
                                            )}
                                            lockVersion={issue.lock_version}
                                            recordLabel={issue.title}
                                            archiveAction={`/projects/${project.id}/issues/${issue.id}/archive`}
                                            restoreAction={`/projects/${project.id}/issues/${issue.id}/restore`}
                                            edit={
                                                <IssueDialog
                                                    projectId={project.id}
                                                    projectName={project.name}
                                                    members={members}
                                                    issue={issue}
                                                />
                                            }
                                        />
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            );
        }

        if (currentTab.id === 'team' && (project.members?.length ?? 0) > 0) {
            return (
                <div className="project-record-list">
                    <header>
                        <h2 id="project-panel-title">فريق المشروع</h2>
                    </header>
                    <ul>
                        {project.members?.map((member) => (
                            <li key={member.id}>
                                <div>
                                    <strong>{member.name}</strong>
                                    <small>
                                        {member.job_title ||
                                            member.email ||
                                            'عضو فريق'}
                                    </small>
                                </div>
                                <span>
                                    {member.pivot?.project_role === 'manager'
                                        ? 'مدير المشروع'
                                        : member.pivot?.project_role ===
                                            'viewer'
                                          ? 'مشاهد'
                                          : 'عضو'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            );
        }

        if (currentTab.id === 'documents') {
            return (
                <div className="project-documents-workspace">
                    <div className="sr-only" aria-live="polite">
                        {documentNotice || documentError}
                    </div>
                    {documentError && (
                        <div className="cloudtech-alert danger" role="alert">
                            <AlertTriangle aria-hidden="true" />
                            <span>{documentError}</span>
                            <button
                                type="button"
                                onClick={() => void loadDocuments()}
                            >
                                إعادة المحاولة
                            </button>
                        </div>
                    )}
                    {documentNotice && (
                        <div className="cloudtech-alert success" role="status">
                            <CheckCircle2 aria-hidden="true" />
                            <span>{documentNotice}</span>
                        </div>
                    )}

                    <section className="project-requirement-book">
                        <header>
                            <div>
                                <FileText aria-hidden="true" />
                                <div>
                                    <h2 id="project-panel-title">
                                        كراسة المتطلبات
                                    </h2>
                                    <p>
                                        سجل إصدارات محفوظ مع نسخة حالية واضحة
                                        ودون حذف الملفات السابقة.
                                    </p>
                                </div>
                            </div>
                            <span>
                                {numberFormatter.format(
                                    requirementBook?.versions.length ?? 0,
                                )}{' '}
                                إصدارات
                            </span>
                        </header>

                        {canManage && (
                            <form
                                className="requirement-book-upload"
                                onSubmit={(event) =>
                                    void uploadRequirementBook(event)
                                }
                            >
                                <label>
                                    <span>عنوان الكراسة</span>
                                    <input
                                        name="title"
                                        required
                                        placeholder="كراسة متطلبات المشروع"
                                    />
                                </label>
                                <label>
                                    <span>الحالة</span>
                                    <select name="status" defaultValue="draft">
                                        <option value="draft">مسودة</option>
                                        <option value="under_review">
                                            قيد المراجعة
                                        </option>
                                        <option value="approved">معتمدة</option>
                                        <option value="superseded">
                                            مستبدلة
                                        </option>
                                    </select>
                                </label>
                                <label className="requirement-book-file">
                                    <UploadCloud aria-hidden="true" />
                                    <span>اختر PDF أو Word أو Excel</span>
                                    <input
                                        name="file"
                                        type="file"
                                        required
                                        accept=".pdf,.docx,.xlsx"
                                    />
                                </label>
                                <label className="requirement-book-current">
                                    <input
                                        name="is_current"
                                        type="checkbox"
                                        value="1"
                                        defaultChecked
                                    />
                                    تعيينه كإصدار حالي
                                </label>
                                <label className="requirement-book-note">
                                    <span>ملاحظة الإصدار (اختيارية)</span>
                                    <input
                                        name="note"
                                        placeholder="ما الذي تغير في هذا الإصدار؟"
                                    />
                                </label>
                                <button
                                    className="cloudtech-primary-action"
                                    type="submit"
                                    disabled={documentBusy}
                                >
                                    <UploadCloud aria-hidden="true" />
                                    {documentBusy
                                        ? 'جارٍ الرفع والفحص…'
                                        : 'رفع إصدار جديد'}
                                </button>
                            </form>
                        )}

                        {documentLoading ? (
                            <div
                                className="project-documents-loading"
                                role="status"
                            >
                                جارٍ تحميل كراسة المتطلبات…
                            </div>
                        ) : (requirementBook?.versions.length ?? 0) === 0 ? (
                            <div className="project-documents-empty">
                                <FileText aria-hidden="true" />
                                <strong>لم تُرفع كراسة متطلبات بعد</strong>
                                <span>
                                    يمكن إنشاء المشروع أولاً وإضافة الكراسة في
                                    أي وقت لاحقاً.
                                </span>
                            </div>
                        ) : (
                            <div className="requirement-book-versions">
                                {requirementBook?.versions.map((version) => (
                                    <article key={version.id}>
                                        <span
                                            className={`requirement-book-status status-${version.status}`}
                                        >
                                            {version.status === 'approved'
                                                ? 'معتمدة'
                                                : version.status ===
                                                    'under_review'
                                                  ? 'قيد المراجعة'
                                                  : version.status ===
                                                      'superseded'
                                                    ? 'مستبدلة'
                                                    : 'مسودة'}
                                        </span>
                                        <div>
                                            <strong>
                                                {version.title ||
                                                    requirementBook?.title ||
                                                    'كراسة المتطلبات'}
                                                {version.is_current && (
                                                    <em>الإصدار الحالي</em>
                                                )}
                                            </strong>
                                            <small>
                                                الإصدار{' '}
                                                {numberFormatter.format(
                                                    version.version_number,
                                                )}{' '}
                                                · {version.uploader.name} ·{' '}
                                                {formatDate(
                                                    version.uploaded_at,
                                                )}
                                            </small>
                                            {version.note && (
                                                <p>{version.note}</p>
                                            )}
                                        </div>
                                        <div className="requirement-book-actions">
                                            {version.file.download_url && (
                                                <a
                                                    href={
                                                        version.file
                                                            .download_url
                                                    }
                                                >
                                                    تنزيل الملف
                                                </a>
                                            )}
                                            {canManage &&
                                                !version.is_current && (
                                                    <button
                                                        type="button"
                                                        disabled={documentBusy}
                                                        onClick={() =>
                                                            void actOnRequirementBookVersion(
                                                                version,
                                                                'make-current',
                                                            )
                                                        }
                                                    >
                                                        تعيين كحالي
                                                    </button>
                                                )}
                                            {canManage && (
                                                <button
                                                    type="button"
                                                    className="is-danger"
                                                    disabled={documentBusy}
                                                    onClick={() =>
                                                        void actOnRequirementBookVersion(
                                                            version,
                                                            'archive',
                                                        )
                                                    }
                                                >
                                                    أرشفة
                                                </button>
                                            )}
                                        </div>
                                    </article>
                                ))}
                            </div>
                        )}
                    </section>

                    <RequirementAnalysisPanel
                        projectId={project.id}
                        versions={requirementBook?.versions ?? []}
                        canManage={canManage}
                    />

                    <div className="project-document-grid">
                        <section className="project-files-panel">
                            <header>
                                <div>
                                    <Paperclip aria-hidden="true" />
                                    <div>
                                        <h2>مرفقات المشروع</h2>
                                        <p>
                                            ملفات مرتبطة بالمشروع أو بمهامه أو
                                            متطلباته.
                                        </p>
                                    </div>
                                </div>
                            </header>
                            {canUploadFile && (
                                <div className="project-file-uploader">
                                    <div className="project-file-target-fields">
                                        <label>
                                            <span>ربط المرفق بـ</span>
                                            <select
                                                value={attachmentTargetType}
                                                disabled={documentBusy}
                                                onChange={(event) => {
                                                    setAttachmentTargetType(
                                                        event.currentTarget
                                                            .value as AttachmentTargetType,
                                                    );
                                                    setAttachmentTargetQuery(
                                                        '',
                                                    );
                                                    setAttachmentTargetId('');
                                                    setAttachmentTargets([]);
                                                    setAttachmentTargetError(
                                                        '',
                                                    );
                                                }}
                                            >
                                                <option value="project">
                                                    المشروع
                                                </option>
                                                <option value="task">
                                                    مهمة
                                                </option>
                                                <option value="requirement">
                                                    متطلب
                                                </option>
                                            </select>
                                        </label>
                                        {attachmentTargetType !== 'project' && (
                                            <>
                                                <label>
                                                    <span>بحث</span>
                                                    <input
                                                        type="search"
                                                        value={
                                                            attachmentTargetQuery
                                                        }
                                                        disabled={documentBusy}
                                                        placeholder="ابحث بالرمز أو العنوان"
                                                        onChange={(event) =>
                                                            setAttachmentTargetQuery(
                                                                event
                                                                    .currentTarget
                                                                    .value,
                                                            )
                                                        }
                                                    />
                                                </label>
                                                <label>
                                                    <span>
                                                        {attachmentTargetType ===
                                                        'task'
                                                            ? 'المهمة'
                                                            : 'المتطلب'}
                                                    </span>
                                                    <select
                                                        value={
                                                            attachmentTargetId
                                                        }
                                                        disabled={
                                                            documentBusy ||
                                                            attachmentTargetsLoading
                                                        }
                                                        onChange={(event) =>
                                                            setAttachmentTargetId(
                                                                event
                                                                    .currentTarget
                                                                    .value,
                                                            )
                                                        }
                                                    >
                                                        <option value="">
                                                            {attachmentTargetsLoading
                                                                ? 'جارٍ التحميل…'
                                                                : 'اختر هدف الربط'}
                                                        </option>
                                                        {attachmentTargets.map(
                                                            (target) => (
                                                                <option
                                                                    key={
                                                                        target.id
                                                                    }
                                                                    value={
                                                                        target.id
                                                                    }
                                                                >
                                                                    {
                                                                        target.code
                                                                    }{' '}
                                                                    —{' '}
                                                                    {
                                                                        target.title
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </label>
                                            </>
                                        )}
                                    </div>
                                    <label
                                        className={`project-file-upload${
                                            attachmentTargetReady
                                                ? ''
                                                : 'is-disabled'
                                        }`}
                                    >
                                        <Plus aria-hidden="true" />
                                        <span>رفع مرفق</span>
                                        <input
                                            type="file"
                                            accept=".pdf,.docx,.xlsx,.csv,.jpg,.jpeg,.png,.webp"
                                            disabled={
                                                documentBusy ||
                                                !attachmentTargetReady
                                            }
                                            onChange={(event) => {
                                                const file =
                                                    event.currentTarget
                                                        .files?.[0];

                                                if (file) {
                                                    void uploadProjectFile(
                                                        file,
                                                    );
                                                }

                                                event.currentTarget.value = '';
                                            }}
                                        />
                                    </label>
                                </div>
                            )}
                            {attachmentTargetError && (
                                <p
                                    className="project-file-target-error"
                                    role="alert"
                                >
                                    {attachmentTargetError}
                                </p>
                            )}
                            {activeProjectFiles.length === 0 ? (
                                <div className="project-documents-empty compact">
                                    <Paperclip aria-hidden="true" />
                                    <strong>لا توجد مرفقات مرتبطة</strong>
                                </div>
                            ) : (
                                <ul className="project-file-list">
                                    {activeProjectFiles.map((file) => (
                                        <li key={file.link_id}>
                                            <FileText aria-hidden="true" />
                                            <div>
                                                <strong>
                                                    {file.original_name}
                                                </strong>
                                                <small>
                                                    <span className="project-file-target-badge">
                                                        {attachmentTargetLabel(
                                                            file,
                                                        )}
                                                    </span>{' '}
                                                    ·{' '}
                                                    {file.uploader?.name ||
                                                        'مستخدم'}{' '}
                                                    ·{' '}
                                                    {formatFileSize(
                                                        file.size_bytes,
                                                    )}
                                                    {!file.download_url &&
                                                        ' · قيد الفحص الأمني'}
                                                </small>
                                            </div>
                                            {file.download_url && (
                                                <a href={file.download_url}>
                                                    تنزيل
                                                </a>
                                            )}
                                            {file.can_archive && (
                                                <button
                                                    type="button"
                                                    className="is-danger"
                                                    disabled={documentBusy}
                                                    onClick={() =>
                                                        void archiveProjectFile(
                                                            file,
                                                        )
                                                    }
                                                >
                                                    أرشفة
                                                </button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {archivedProjectFiles.length > 0 && (
                                <details className="project-file-archive">
                                    <summary>
                                        المرفقات المؤرشفة (
                                        {archivedProjectFiles.length})
                                    </summary>
                                    <ul className="project-file-list">
                                        {archivedProjectFiles.map((file) => (
                                            <li
                                                key={file.link_id}
                                                className="is-archived"
                                            >
                                                <FileText aria-hidden="true" />
                                                <div>
                                                    <strong>
                                                        {file.original_name}
                                                    </strong>
                                                    <small>
                                                        <span className="project-file-target-badge">
                                                            {attachmentTargetLabel(
                                                                file,
                                                            )}
                                                        </span>{' '}
                                                        · مؤرشف · محفوظ في سجل
                                                        المشروع
                                                    </small>
                                                </div>
                                                {file.can_restore && (
                                                    <button
                                                        type="button"
                                                        disabled={documentBusy}
                                                        onClick={() =>
                                                            void restoreProjectFile(
                                                                file,
                                                            )
                                                        }
                                                    >
                                                        استعادة
                                                    </button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </details>
                            )}
                        </section>
                    </div>
                </div>
            );
        }

        if (currentTab.id === 'client' && project.client) {
            const client =
                typeof project.client === 'string' ? null : project.client;

            return (
                <div className="project-client-panel">
                    <article>
                        <p className="cloudtech-eyebrow">العميل المرتبط</p>
                        <h2 id="project-panel-title">
                            {clientName(project.client)}
                        </h2>
                        <dl>
                            <div>
                                <dt>البريد</dt>
                                <dd dir="ltr">{client?.email || 'غير مسجل'}</dd>
                            </div>
                            <div>
                                <dt>الهاتف</dt>
                                <dd dir="ltr">{client?.phone || 'غير مسجل'}</dd>
                            </div>
                            <div>
                                <dt>العنوان</dt>
                                <dd>{client?.address || 'غير مسجل'}</dd>
                            </div>
                        </dl>
                        {client?.id && (
                            <Link href={`/clients/${client.id}`}>
                                فتح ملف العميل
                                <ArrowRight aria-hidden="true" />
                            </Link>
                        )}
                    </article>
                    <article>
                        <p className="cloudtech-eyebrow">جهات الاتصال</p>
                        <h2>التواصل في المشروع</h2>
                        {(client?.contacts?.length ?? 0) === 0 ? (
                            <p>لا توجد جهات اتصال نشطة.</p>
                        ) : (
                            <ul>
                                {client?.contacts?.map((contact) => (
                                    <li key={contact.id}>
                                        <strong>{contact.name}</strong>
                                        <small>
                                            {contact.role || 'جهة اتصال'}
                                        </small>
                                        <span dir="ltr">
                                            {contact.email ||
                                                contact.phone ||
                                                '—'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </article>
                </div>
            );
        }

        if (currentTab.id === 'activity' && activityRows.length > 0) {
            return (
                <div className="project-activity-panel">
                    <h2 id="project-panel-title">سجل نشاط المشروع</h2>
                    <ol>
                        {activityRows.map((entry) => (
                            <li key={entry.id}>
                                <span aria-hidden="true" />
                                <div>
                                    <strong>
                                        {entry.action.replaceAll('_', ' ')}
                                    </strong>
                                    <small>
                                        {entry.actor || 'النظام'} ·{' '}
                                        <time dateTime={entry.created_at}>
                                            {formatDate(entry.created_at)}
                                        </time>
                                    </small>
                                </div>
                            </li>
                        ))}
                    </ol>
                    {activityPagination && activityPagination.last_page > 1 && (
                        <nav
                            className="project-activity-pagination"
                            aria-label="صفحات سجل نشاط المشروع"
                        >
                            {activityPagination.prev_page_url ? (
                                <Link
                                    href={activityPagination.prev_page_url}
                                    preserveScroll
                                >
                                    الأحدث
                                </Link>
                            ) : (
                                <span aria-hidden="true">الأحدث</span>
                            )}
                            <span>
                                صفحة{' '}
                                <bdi dir="ltr">
                                    {activityPagination.current_page} /{' '}
                                    {activityPagination.last_page}
                                </bdi>
                            </span>
                            {activityPagination.next_page_url ? (
                                <Link
                                    href={activityPagination.next_page_url}
                                    preserveScroll
                                >
                                    الأقدم
                                </Link>
                            ) : (
                                <span aria-hidden="true">الأقدم</span>
                            )}
                        </nav>
                    )}
                </div>
            );
        }

        return (
            <EmptyProjectPanel
                icon={currentTab.icon}
                label={currentTab.label}
                title={
                    governanceArchivedMode && isGovernanceTab
                        ? 'لا توجد سجلات مؤرشفة'
                        : 'لا توجد بيانات بعد'
                }
                description={currentTab.description}
                action={
                    currentTab.id === 'requirements' ? (
                        <div className="project-panel-actions">
                            {!governanceArchivedMode && canManage && (
                                <RequirementDialog
                                    projectId={project.id}
                                    projectName={project.name}
                                    members={members}
                                    statuses={requirementStatuses}
                                />
                            )}
                            <GovernanceViewToggle
                                projectId={project.id}
                                tab="requirements"
                                archived={governanceArchivedMode}
                            />
                        </div>
                    ) : governanceArchivedMode &&
                      isGovernanceTab &&
                      canManage ? (
                        <GovernanceViewToggle
                            projectId={project.id}
                            tab={currentTab.id}
                            archived
                        />
                    ) : currentTab.id === 'tasks' && canCreateTask ? (
                        <Link
                            className="cloudtech-primary-action"
                            href={`/tasks/create?project=${project.id}`}
                        >
                            <Plus aria-hidden="true" />
                            إضافة مهمة
                        </Link>
                    ) : currentTab.id === 'timeline' && canManage ? (
                        <div className="project-panel-actions">
                            <TimelineDialog
                                projectId={project.id}
                                projectName={project.name}
                                members={members}
                            />
                            <MeetingDialog
                                projectId={project.id}
                                projectName={project.name}
                                members={members}
                            />
                            <GovernanceViewToggle
                                projectId={project.id}
                                tab="timeline"
                                archived={false}
                            />
                        </div>
                    ) : currentTab.id === 'meetings' && canManage ? (
                        <div className="project-panel-actions">
                            <MeetingDialog
                                projectId={project.id}
                                projectName={project.name}
                                members={members}
                            />
                            <GovernanceViewToggle
                                projectId={project.id}
                                tab="meetings"
                                archived={false}
                            />
                        </div>
                    ) : currentTab.id === 'risks' && canManage ? (
                        <div className="project-panel-actions">
                            <RiskDialog
                                projectId={project.id}
                                projectName={project.name}
                                members={members}
                            />
                            <GovernanceViewToggle
                                projectId={project.id}
                                tab="risks"
                                archived={false}
                            />
                        </div>
                    ) : currentTab.id === 'issues' && canManage ? (
                        <div className="project-panel-actions">
                            <IssueDialog
                                projectId={project.id}
                                projectName={project.name}
                                members={members}
                            />
                            <GovernanceViewToggle
                                projectId={project.id}
                                tab="issues"
                                archived={false}
                            />
                        </div>
                    ) : undefined
                }
            />
        );
    };

    return (
        <>
            <Head title={project.name} />
            <div className="cloudtech-page project-page">
                <div className="project-page-toolbar">
                    <Link className="project-back-link" href="/projects">
                        <ArrowRight aria-hidden="true" />
                        العودة إلى المشاريع
                    </Link>
                    <div className="project-page-actions">
                        <a
                            className="project-secondary-action"
                            href={`/projects/${project.id}/summary.pdf`}
                        >
                            <FileDown aria-hidden="true" />
                            تنزيل ملخص PDF
                        </a>
                        {canManage && (
                            <EditProjectDialog
                                project={project}
                                statuses={projectStatuses}
                                clients={clients}
                                members={availableMembers}
                            />
                        )}
                        {canArchive && (
                            <Form
                                action={`/projects/${project.id}/archive`}
                                method="post"
                                onBefore={() =>
                                    window.confirm(
                                        `هل تريد أرشفة «${project.name}»؟ ستبقى بياناته وسجله محفوظين للقراءة.`,
                                    )
                                }
                            >
                                {({ processing }) => (
                                    <button
                                        type="submit"
                                        className="project-danger-action"
                                        disabled={processing}
                                    >
                                        <Archive aria-hidden="true" />
                                        أرشفة المشروع
                                    </button>
                                )}
                            </Form>
                        )}
                        {canRestore && (
                            <Form
                                action={`/projects/${project.id}/restore`}
                                method="post"
                            >
                                {({ processing }) => (
                                    <button
                                        type="submit"
                                        className="project-secondary-action"
                                        disabled={processing}
                                    >
                                        <Archive aria-hidden="true" />
                                        استعادة المشروع
                                    </button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>

                <section
                    className="project-hero"
                    aria-labelledby="project-title"
                >
                    <div className="project-hero-copy">
                        <div className="project-identity-line">
                            <span className="dashboard-code" dir="ltr">
                                {project.code || '—'}
                            </span>
                            <span
                                className="project-status-dot"
                                aria-hidden="true"
                            />
                            <span>{projectStatus || 'دون حالة'}</span>
                            {project.priority && (
                                <span className="project-priority">
                                    أولوية{' '}
                                    {priorityLabels[project.priority] ??
                                        project.priority}
                                </span>
                            )}
                        </div>
                        <h1 id="project-title" tabIndex={-1}>
                            {project.name}
                        </h1>
                        <p>
                            {project.description || clientName(project.client)}
                        </p>
                        <dl className="project-dates">
                            <div>
                                <dt>البداية</dt>
                                <dd>
                                    {formatDate(
                                        project.startDate ?? project.start_date,
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt>النهاية المستهدفة</dt>
                                <dd>
                                    {formatDate(
                                        project.endDate ?? project.end_date,
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt>العميل</dt>
                                <dd>{clientName(project.client)}</dd>
                            </div>
                        </dl>
                    </div>

                    <div className="project-progress-card">
                        <div
                            className="project-progress-ring"
                            style={
                                {
                                    '--progress': `${progress * 3.6}deg`,
                                } as React.CSSProperties
                            }
                        >
                            <span>
                                {hasProgress
                                    ? `${numberFormatter.format(progress)}٪`
                                    : '—'}
                            </span>
                        </div>
                        <div>
                            <strong>تقدم المشروع</strong>
                            <p>
                                {healthLabel(metrics?.health || project.health)}
                            </p>
                        </div>
                        {canCreateTask && (
                            <Link href={`/tasks/create?project=${project.id}`}>
                                <Plus aria-hidden="true" />
                                إضافة مهمة
                            </Link>
                        )}
                    </div>
                </section>

                <section
                    className="project-metrics"
                    aria-label="مؤشرات المشروع"
                >
                    <div>
                        <CheckCircle2 aria-hidden="true" />
                        <span>المهام المفتوحة</span>
                        <strong>
                            {formatMetric(
                                metrics?.openTasks ?? metrics?.open_tasks,
                            )}
                        </strong>
                    </div>
                    <div>
                        <AlertTriangle aria-hidden="true" />
                        <span>المهام المتأخرة</span>
                        <strong>
                            {formatMetric(
                                metrics?.overdueTasks ?? metrics?.overdue_tasks,
                            )}
                        </strong>
                    </div>
                    <div>
                        <FileText aria-hidden="true" />
                        <span>المتطلبات</span>
                        <strong>{formatMetric(metrics?.requirements)}</strong>
                    </div>
                    <div>
                        <Flag aria-hidden="true" />
                        <span>المخاطر المرتفعة</span>
                        <strong>
                            {formatMetric(
                                metrics?.highRisks ?? metrics?.high_risks,
                            )}
                        </strong>
                    </div>
                </section>

                <nav
                    ref={tabListRef}
                    className="project-tabs"
                    aria-label="أقسام المشروع"
                >
                    {tabs.map((tab) => {
                        const Icon = tab.icon;

                        return (
                            <Link
                                key={tab.id}
                                ref={
                                    currentTab.id === tab.id
                                        ? activeTabRef
                                        : undefined
                                }
                                href={`/projects/${project.id}?tab=${tab.id}`}
                                aria-current={
                                    currentTab.id === tab.id
                                        ? 'page'
                                        : undefined
                                }
                            >
                                <Icon aria-hidden="true" />
                                {tab.label}
                            </Link>
                        );
                    })}
                </nav>

                <section
                    className="project-tab-panel"
                    aria-labelledby="project-panel-title"
                >
                    {renderPanel()}
                    {tabPagination && tabPagination.last_page > 1 && (
                        <nav
                            className="project-activity-pagination project-tab-pagination"
                            aria-label={`صفحات ${currentTab.label}`}
                        >
                            {tabPagination.prev_page_url ? (
                                <Link
                                    href={tabPagination.prev_page_url}
                                    preserveScroll
                                >
                                    الأحدث
                                </Link>
                            ) : (
                                <span aria-hidden="true">الأحدث</span>
                            )}
                            <span aria-live="polite">
                                {numberFormatter.format(
                                    tabPagination.current_page,
                                )}{' '}
                                من{' '}
                                {numberFormatter.format(
                                    tabPagination.last_page,
                                )}{' '}
                                · {numberFormatter.format(tabPagination.total)}{' '}
                                سجلاً إجمالاً
                            </span>
                            {tabPagination.next_page_url ? (
                                <Link
                                    href={tabPagination.next_page_url}
                                    preserveScroll
                                >
                                    الأقدم
                                </Link>
                            ) : (
                                <span aria-hidden="true">الأقدم</span>
                            )}
                        </nav>
                    )}
                </section>
            </div>
        </>
    );
}

ProjectShow.layout = {
    breadcrumbs: [
        { title: 'المشاريع', href: '/projects' },
        { title: 'مساحة المشروع', href: '#' },
    ],
};

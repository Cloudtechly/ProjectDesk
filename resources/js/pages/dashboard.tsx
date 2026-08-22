import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    BarChart3,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    Clock3,
    FolderKanban,
    HeartPulse,
    ListChecks,
    Plus,
    ShieldAlert,
    Users,
} from 'lucide-react';
import type { ComponentType, SVGProps } from 'react';
import {
    createLocaleDateTimeFormatter,
    createLocaleNumberFormatter,
} from '@/i18n/formatters';
import { dashboard } from '@/routes';

type Summary = {
    activeProjects?: number | null;
    overdueTasks?: number | null;
    dueSoonTasks?: number | null;
    highRisks?: number | null;
};

type Project = {
    id: number | string;
    code?: string | null;
    name: string;
    client?: string | null;
    status?: string | null;
    statusColor?: string | null;
    priority?: string | null;
    progress?: number | null;
    health?: string | null;
    startDate?: string | null;
    endDate?: string | null;
    nextStage?: {
        id: number | string;
        title: string;
        kind?: string | null;
        status?: string | null;
        starts_at?: string | null;
    } | null;
};

type Task = {
    id: number | string;
    code?: string | null;
    title: string;
    project?: string | null;
    assignee?: string | null;
    status?: string | null;
    dueAt?: string | null;
    isOverdue?: boolean;
    href?: string;
};

type Risk = {
    id: number | string;
    title: string;
    projectId?: number | string | null;
    project?: string | null;
    score?: number | string | null;
    status?: string | null;
    href?: string;
};

type Issue = {
    id: number | string;
    title: string;
    projectId?: number | string | null;
    project?: string | null;
    severity?: string | null;
    status?: string | null;
    dueAt?: string | null;
    href?: string;
};

type Workload = {
    id: number | string;
    name: string;
    open?: number | null;
    overdue?: number | null;
    href?: string | null;
};

type DistributionItem = {
    id?: number | string;
    key?: string;
    label: string;
    color?: string | null;
    count: number;
    href: string;
};

type WeeklyBar = {
    id: number | string;
    type?: string | null;
    title: string;
    startColumn: number;
    span: number;
    status?: string | null;
    lane?: number;
    continuesBefore?: boolean;
    continuesAfter?: boolean;
    href?: string;
    startsAt?: string;
    endsAt?: string;
};

type WeeklySchedule = {
    weekStart?: string | null;
    weekEnd?: string | null;
    days?: Array<{ date: string; label: string; isToday?: boolean }>;
    rows?: Array<{
        project:
            | string
            | { id: number | string; code?: string | null; name: string };
        bars?: WeeklyBar[];
        laneCount?: number;
        totalBarCount?: number;
        hiddenCount?: number;
    }>;
};

type DashboardProps = {
    summary?: Summary | null;
    projects?: Project[];
    tasks?: Task[];
    risks?: Risk[];
    issues?: Issue[];
    workload?: Workload[];
    taskStatusDistribution?: DistributionItem[];
    projectHealthDistribution?: DistributionItem[];
    weeklySchedule?: WeeklySchedule | null;
    currentWeek?: string | null;
    selectedDate?: string | null;
    canCreateTask?: boolean;
};

type IconComponent = ComponentType<SVGProps<SVGSVGElement>>;

const numberFormatter = createLocaleNumberFormatter();
const dateFormatter = createLocaleDateTimeFormatter({
    day: 'numeric',
    month: 'short',
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

const healthColors: Record<string, string> = {
    danger: '#c44545',
    attention: '#c27a16',
    healthy: '#16866e',
};

function formatMetric(value?: number | null) {
    return typeof value === 'number' ? numberFormatter.format(value) : '—';
}

function formatDate(value?: string | null) {
    if (!value) {
        return 'دون موعد';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

function clampProgress(value?: number | null) {
    if (typeof value !== 'number') {
        return 0;
    }

    return Math.min(100, Math.max(0, value));
}

function weeklyBarAccessibleName(bar: WeeklyBar, projectName: string) {
    const kind = bar.type === 'meeting' ? 'اجتماع' : 'مهمة';
    const range = `${formatDate(bar.startsAt)} إلى ${formatDate(bar.endsAt)}`;
    const continuation = [
        bar.continuesBefore ? 'بدأ قبل الأسبوع' : '',
        bar.continuesAfter ? 'يستمر بعد الأسبوع' : '',
    ]
        .filter(Boolean)
        .join('، ');

    return [
        kind,
        bar.title,
        `المشروع ${projectName}`,
        range,
        bar.status ? `الحالة ${bar.status}` : '',
        continuation,
        `فتح ${kind === 'اجتماع' ? 'المشروع' : 'المهمة'}`,
    ]
        .filter(Boolean)
        .join('، ');
}

function MetricCard({
    label,
    value,
    description,
    href,
    icon: Icon,
    tone,
}: {
    label: string;
    value?: number | null;
    description: string;
    href: string;
    icon: IconComponent;
    tone: 'brand' | 'danger' | 'warning' | 'ink';
}) {
    return (
        <Link className={`dashboard-metric tone-${tone}`} href={href}>
            <span className="dashboard-metric-icon">
                <Icon aria-hidden="true" />
            </span>
            <span className="dashboard-metric-copy">
                <span>{label}</span>
                <strong>{formatMetric(value)}</strong>
                <small>{description}</small>
            </span>
            <ArrowLeft className="dashboard-metric-arrow" aria-hidden="true" />
        </Link>
    );
}

function SectionEmpty({
    icon: Icon,
    title,
    description,
}: {
    icon: IconComponent;
    title: string;
    description: string;
}) {
    return (
        <div className="dashboard-section-empty">
            <Icon aria-hidden="true" />
            <div>
                <strong>{title}</strong>
                <p>{description}</p>
            </div>
        </div>
    );
}

function ProjectList({ projects }: { projects: Project[] }) {
    if (projects.length === 0) {
        return (
            <SectionEmpty
                icon={FolderKanban}
                title="لا توجد مشاريع نشطة"
                description="ستظهر المشاريع هنا بعد إنشاء أول مشروع."
            />
        );
    }

    return (
        <ul className="dashboard-project-list">
            {projects.slice(0, 5).map((project) => {
                const progress = clampProgress(project.progress);

                return (
                    <li key={project.id}>
                        <Link href={`/projects/${project.id}`}>
                            <div className="dashboard-project-topline">
                                <div>
                                    <span className="dashboard-code" dir="ltr">
                                        {project.code || '—'}
                                    </span>
                                    <strong>{project.name}</strong>
                                </div>
                                <span className="dashboard-project-progress">
                                    {typeof project.progress === 'number'
                                        ? `${numberFormatter.format(progress)}٪`
                                        : '—'}
                                </span>
                            </div>
                            <div
                                className="dashboard-progress-track"
                                role="progressbar"
                                aria-label={`تقدم ${project.name}`}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-valuenow={
                                    typeof project.progress === 'number'
                                        ? progress
                                        : undefined
                                }
                            >
                                <span style={{ width: `${progress}%` }} />
                            </div>
                            <div className="dashboard-project-meta">
                                <span>{project.client || 'دون عميل'}</span>
                                <span>{project.status || 'دون حالة'}</span>
                                <span>
                                    أولوية{' '}
                                    {priorityLabels[project.priority || ''] ||
                                        'غير محددة'}
                                </span>
                                <span
                                    className={`dashboard-health-label health-${project.health || 'healthy'}`}
                                >
                                    {healthLabels[project.health || ''] ||
                                        'دون مؤشر صحة'}
                                </span>
                                <span>ينتهي {formatDate(project.endDate)}</span>
                            </div>
                            <div className="dashboard-project-next-stage">
                                <span>المرحلة القادمة</span>
                                <strong>
                                    {project.nextStage?.title ||
                                        'لا توجد مرحلة قادمة'}
                                </strong>
                                {project.nextStage?.starts_at && (
                                    <time
                                        dateTime={project.nextStage.starts_at}
                                    >
                                        {formatDate(
                                            project.nextStage.starts_at,
                                        )}
                                    </time>
                                )}
                            </div>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}

function TaskList({ tasks }: { tasks: Task[] }) {
    if (tasks.length === 0) {
        return (
            <SectionEmpty
                icon={ListChecks}
                title="لا توجد مهام تتطلب الانتباه"
                description="المهام المتأخرة والقريبة ستظهر هنا تلقائياً."
            />
        );
    }

    return (
        <ul className="dashboard-task-list">
            {tasks.slice(0, 6).map((task) => (
                <li key={task.id}>
                    <Link
                        href={
                            task.href ||
                            `/tasks?q=${encodeURIComponent(task.code || String(task.id))}`
                        }
                    >
                        <span
                            className={`dashboard-task-mark ${task.isOverdue ? 'is-overdue' : ''}`}
                            aria-hidden="true"
                        />
                        <span className="dashboard-task-copy">
                            <strong>{task.title}</strong>
                            <small>
                                {task.project || 'دون مشروع'}
                                {' · '}
                                {task.assignee || 'غير مسندة'}
                            </small>
                        </span>
                        <time dateTime={task.dueAt || undefined}>
                            {formatDate(task.dueAt)}
                        </time>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function RiskList({ risks }: { risks: Risk[] }) {
    if (risks.length === 0) {
        return (
            <SectionEmpty
                icon={ShieldAlert}
                title="لا توجد مخاطر مرتفعة"
                description="ستظهر المخاطر المهمة هنا عند تسجيلها."
            />
        );
    }

    return (
        <ul className="dashboard-risk-list">
            {risks.slice(0, 4).map((risk) => (
                <li key={risk.id}>
                    <Link
                        className="dashboard-risk-link"
                        href={
                            risk.href ||
                            (risk.projectId
                                ? `/projects/${risk.projectId}?tab=risks`
                                : '/projects?risk=high')
                        }
                    >
                        <span className="dashboard-risk-score">
                            {risk.score ?? '—'}
                        </span>
                        <div>
                            <strong>{risk.title}</strong>
                            <small>
                                {risk.project || 'دون مشروع'}
                                {risk.status ? ` · ${risk.status}` : ''}
                            </small>
                        </div>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function IssueList({ issues }: { issues: Issue[] }) {
    if (issues.length === 0) {
        return (
            <SectionEmpty
                icon={CircleAlert}
                title="لا توجد مشكلات مهمة مفتوحة"
                description="ستظهر المشكلات العالية والحرجة هنا حتى حلها."
            />
        );
    }

    const severityLabels: Record<string, string> = {
        high: 'عالية',
        critical: 'حرجة',
    };

    return (
        <ul className="dashboard-risk-list dashboard-issue-list">
            {issues.slice(0, 4).map((issue) => (
                <li key={issue.id}>
                    <Link
                        className="dashboard-risk-link"
                        href={
                            issue.href ||
                            `/projects/${issue.projectId}?tab=issues`
                        }
                    >
                        <span
                            className={`dashboard-issue-severity severity-${issue.severity || 'high'}`}
                        >
                            {severityLabels[issue.severity || ''] || 'مهمة'}
                        </span>
                        <div>
                            <strong>{issue.title}</strong>
                            <small>
                                {issue.project || 'دون مشروع'}
                                {issue.dueAt
                                    ? ` · تستحق ${formatDate(issue.dueAt)}`
                                    : ''}
                            </small>
                        </div>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function DistributionChart({
    items,
    emptyTitle,
    emptyDescription,
}: {
    items: DistributionItem[];
    emptyTitle: string;
    emptyDescription: string;
}) {
    const total = items.reduce((sum, item) => sum + item.count, 0);

    if (total === 0) {
        return (
            <SectionEmpty
                icon={BarChart3}
                title={emptyTitle}
                description={emptyDescription}
            />
        );
    }

    return (
        <ul className="dashboard-distribution-list">
            {items.map((item) => {
                const percentage = Math.round((item.count / total) * 100);
                const itemKey = String(item.id ?? item.key ?? item.label);
                const color =
                    item.color || healthColors[item.key || ''] || '#158693';

                return (
                    <li key={itemKey}>
                        <Link
                            href={item.href}
                            aria-label={`${item.label}: ${numberFormatter.format(item.count)} من ${numberFormatter.format(total)}، ${numberFormatter.format(percentage)} بالمئة. فتح القائمة المفلترة`}
                        >
                            <span className="dashboard-distribution-copy">
                                <strong>{item.label}</strong>
                                <small>
                                    {numberFormatter.format(item.count)} ·{' '}
                                    {numberFormatter.format(percentage)}٪
                                </small>
                            </span>
                            <span
                                className="dashboard-distribution-track"
                                aria-hidden="true"
                            >
                                <span
                                    style={{
                                        width: `${percentage}%`,
                                        backgroundColor: color,
                                    }}
                                />
                            </span>
                            <ArrowLeft aria-hidden="true" />
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}

function WorkloadChart({ workload }: { workload: Workload[] }) {
    if (workload.length === 0) {
        return (
            <SectionEmpty
                icon={Users}
                title="لا توجد بيانات عبء عمل"
                description="أضف الفريق وأسند المهام لعرض التوزيع."
            />
        );
    }

    const maxOpen = Math.max(1, ...workload.map((member) => member.open ?? 0));

    return (
        <ul className="dashboard-workload-list">
            {workload.slice(0, 6).map((member) => {
                const open = member.open ?? 0;
                const overdue = member.overdue ?? 0;
                const content = (
                    <>
                        <div className="dashboard-workload-label">
                            <strong
                                data-translate={
                                    member.id === 'unassigned'
                                        ? true
                                        : undefined
                                }
                            >
                                {member.name}
                            </strong>
                            <span>
                                {numberFormatter.format(open)} مفتوحة ·{' '}
                                {numberFormatter.format(overdue)} متأخرة
                            </span>
                        </div>
                        <div
                            className="dashboard-workload-track"
                            aria-label={`${member.name}: ${open} مهام مفتوحة، ${overdue} متأخرة`}
                        >
                            <span
                                className="dashboard-workload-open"
                                style={{ width: `${(open / maxOpen) * 100}%` }}
                            />
                            {overdue > 0 && (
                                <span
                                    className="dashboard-workload-overdue"
                                    style={{
                                        width: `${Math.min(100, (overdue / maxOpen) * 100)}%`,
                                    }}
                                />
                            )}
                        </div>
                    </>
                );

                return (
                    <li key={member.id}>
                        {member.href ? (
                            <Link
                                href={member.href}
                                aria-label={`${member.name}: فتح ${open} مهام مفتوحة مفلترة`}
                            >
                                {content}
                            </Link>
                        ) : (
                            <div>{content}</div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

function WeeklySchedulePanel({
    schedule,
    currentWeek,
    selectedDate,
}: {
    schedule?: WeeklySchedule | null;
    currentWeek?: string | null;
    selectedDate?: string | null;
}) {
    const days = schedule?.days ?? [];
    const rows = schedule?.rows ?? [];
    const weekQuery = currentWeek || schedule?.weekStart || '';

    return (
        <section
            className="dashboard-panel dashboard-weekly"
            aria-labelledby="weekly-title"
        >
            <div className="dashboard-panel-head dashboard-weekly-head">
                <div>
                    <p className="cloudtech-eyebrow">التخطيط الحالي</p>
                    <h2 id="weekly-title">الجدول الأسبوعي للمشاريع</h2>
                    <p>
                        {schedule?.weekStart && schedule?.weekEnd
                            ? `${formatDate(schedule.weekStart)} — ${formatDate(schedule.weekEnd)}`
                            : 'اختر أسبوعاً لمراجعة امتداد المهام عبر الأيام.'}
                    </p>
                </div>
                <div className="dashboard-week-controls">
                    <form action="/dashboard" method="get">
                        <label>
                            <span>تاريخ داخل الأسبوع</span>
                            <input
                                type="date"
                                name="week"
                                defaultValue={
                                    selectedDate ||
                                    currentWeek ||
                                    schedule?.weekStart ||
                                    ''
                                }
                            />
                        </label>
                        <button type="submit">انتقال</button>
                    </form>
                    <Link
                        href={`/dashboard?week=${encodeURIComponent(weekQuery)}&direction=previous`}
                        aria-label="الأسبوع السابق"
                    >
                        <ChevronRight aria-hidden="true" />
                    </Link>
                    <Link className="dashboard-week-today" href="/dashboard">
                        هذا الأسبوع
                    </Link>
                    <Link
                        href={`/dashboard?week=${encodeURIComponent(weekQuery)}&direction=next`}
                        aria-label="الأسبوع التالي"
                    >
                        <ChevronLeft aria-hidden="true" />
                    </Link>
                </div>
            </div>

            {days.length === 0 || rows.length === 0 ? (
                <SectionEmpty
                    icon={CalendarDays}
                    title="لا توجد جدولة لهذا الأسبوع"
                    description="أضف تاريخ بداية ونهاية للمهام لتظهر على الجدول."
                />
            ) : (
                <div
                    className="dashboard-weekly-scroll"
                    tabIndex={0}
                    role="region"
                    aria-label="جدول المشاريع للأسبوع المختار"
                >
                    <div className="dashboard-weekly-grid">
                        <div className="dashboard-weekly-project-head">
                            المشروع
                        </div>
                        {days.map((day) => (
                            <div
                                key={day.date}
                                className={`dashboard-weekly-day ${day.isToday ? 'is-today' : ''}`}
                            >
                                <span>{day.label}</span>
                                <time dateTime={day.date}>
                                    {formatDate(day.date)}
                                </time>
                            </div>
                        ))}
                        {rows.map((row, rowIndex) => {
                            const project =
                                typeof row.project === 'string'
                                    ? { name: row.project }
                                    : row.project;
                            const laneCount = Math.max(
                                1,
                                row.laneCount ??
                                    Math.max(
                                        1,
                                        ...(row.bars ?? []).map(
                                            (bar) => bar.lane ?? 1,
                                        ),
                                    ),
                            );

                            return (
                                <div
                                    className="dashboard-weekly-row"
                                    key={`${project.name}-${rowIndex}`}
                                    style={
                                        {
                                            '--lane-count': laneCount,
                                        } as React.CSSProperties
                                    }
                                >
                                    <div className="dashboard-weekly-project">
                                        <strong>{project.name}</strong>
                                        {(row.hiddenCount ?? 0) > 0 && (
                                            <Link
                                                href={
                                                    typeof row.project ===
                                                    'string'
                                                        ? '/tasks'
                                                        : `/tasks?project=${encodeURIComponent(String(row.project.id))}`
                                                }
                                                className="dashboard-weekly-more"
                                                aria-label={`عرض ${numberFormatter.format(row.hiddenCount ?? 0)} مهمة إضافية في ${project.name}`}
                                            >
                                                +
                                                <bdi dir="ltr">
                                                    {numberFormatter.format(
                                                        row.hiddenCount ?? 0,
                                                    )}
                                                </bdi>
                                            </Link>
                                        )}
                                    </div>
                                    <div
                                        className="dashboard-weekly-cells"
                                        aria-hidden="true"
                                    >
                                        {days.map((day) => (
                                            <span
                                                key={day.date}
                                                className={
                                                    day.isToday
                                                        ? 'is-today'
                                                        : ''
                                                }
                                            />
                                        ))}
                                    </div>
                                    {(row.bars ?? []).map((bar) => {
                                        const start = Math.max(
                                            1,
                                            Math.min(
                                                days.length,
                                                bar.startColumn,
                                            ),
                                        );
                                        const span = Math.max(
                                            1,
                                            Math.min(
                                                bar.span,
                                                days.length - start + 1,
                                            ),
                                        );

                                        return (
                                            <Link
                                                key={bar.id}
                                                href={
                                                    bar.href ||
                                                    (typeof row.project ===
                                                    'string'
                                                        ? '/projects'
                                                        : `/projects/${row.project.id}?tab=timeline`)
                                                }
                                                className={`dashboard-weekly-bar type-${bar.type || 'task'} status-${bar.status || 'default'} ${bar.continuesBefore ? 'continues-before' : ''} ${bar.continuesAfter ? 'continues-after' : ''}`}
                                                style={{
                                                    gridColumn: `${start + 1} / span ${span}`,
                                                    gridRow: bar.lane ?? 1,
                                                }}
                                                title={bar.title}
                                                aria-label={weeklyBarAccessibleName(
                                                    bar,
                                                    project.name,
                                                )}
                                            >
                                                {bar.type === 'meeting' && (
                                                    <span className="dashboard-weekly-kind">
                                                        اجتماع
                                                    </span>
                                                )}
                                                {bar.title}
                                            </Link>
                                        );
                                    })}
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </section>
    );
}

export default function Dashboard({
    summary,
    projects = [],
    tasks = [],
    risks = [],
    issues = [],
    workload = [],
    taskStatusDistribution = [],
    projectHealthDistribution = [],
    weeklySchedule,
    currentWeek,
    selectedDate,
    canCreateTask = false,
}: DashboardProps) {
    return (
        <>
            <Head title="لوحة المتابعة" />
            <div className="cloudtech-page dashboard-page">
                <header className="cloudtech-page-head dashboard-page-head">
                    <div>
                        <p className="cloudtech-eyebrow">غرفة قيادة هادئة</p>
                        <h1 tabIndex={-1}>لوحة المتابعة</h1>
                        <p>
                            اقرأ وضع العمل، ثم انتقل مباشرة إلى ما يحتاج قرارك.
                        </p>
                    </div>
                    {canCreateTask && (
                        <Link
                            className="cloudtech-primary-action"
                            href="/tasks/create"
                        >
                            <Plus aria-hidden="true" />
                            إضافة مهمة
                        </Link>
                    )}
                </header>

                <section className="dashboard-metrics" aria-label="ملخص العمل">
                    <MetricCard
                        label="المشاريع النشطة"
                        value={summary?.activeProjects}
                        description="المشاريع الجاري تنفيذها"
                        href="/projects?activity=active"
                        icon={FolderKanban}
                        tone="brand"
                    />
                    <MetricCard
                        label="المهام المتأخرة"
                        value={summary?.overdueTasks}
                        description="تحتاج معالجة أو إعادة جدولة"
                        href="/tasks?due=overdue"
                        icon={CircleAlert}
                        tone="danger"
                    />
                    <MetricCard
                        label="مستحقة قريباً"
                        value={summary?.dueSoonTasks}
                        description="ضمن نافذة المتابعة القريبة"
                        href="/tasks?due=soon"
                        icon={Clock3}
                        tone="warning"
                    />
                    <MetricCard
                        label="مشاريع بمخاطر مرتفعة"
                        value={summary?.highRisks}
                        description="عدد المشاريع المطابقة للقائمة المفلترة"
                        href="/projects?risk=high"
                        icon={AlertTriangle}
                        tone="ink"
                    />
                </section>

                <div className="dashboard-primary-grid">
                    <section
                        className="dashboard-panel"
                        aria-labelledby="projects-title"
                    >
                        <div className="dashboard-panel-head">
                            <div>
                                <p className="cloudtech-eyebrow">
                                    إيقاع التنفيذ
                                </p>
                                <h2 id="projects-title">المشاريع النشطة</h2>
                            </div>
                            <Link href="/projects">
                                عرض الكل
                                <ArrowLeft aria-hidden="true" />
                            </Link>
                        </div>
                        <ProjectList projects={projects} />
                    </section>

                    <section
                        className="dashboard-panel"
                        aria-labelledby="tasks-title"
                    >
                        <div className="dashboard-panel-head">
                            <div>
                                <p className="cloudtech-eyebrow">
                                    تحتاج انتباهك
                                </p>
                                <h2 id="tasks-title">
                                    المهام القريبة والمتأخرة
                                </h2>
                            </div>
                            <Link href="/tasks">
                                فتح العمل
                                <ArrowLeft aria-hidden="true" />
                            </Link>
                        </div>
                        <TaskList tasks={tasks} />
                    </section>
                </div>

                <WeeklySchedulePanel
                    schedule={weeklySchedule}
                    currentWeek={currentWeek}
                    selectedDate={selectedDate}
                />

                <div className="dashboard-analytics-grid">
                    <section
                        className="dashboard-panel"
                        aria-labelledby="task-status-chart-title"
                    >
                        <div className="dashboard-panel-head">
                            <div>
                                <p className="cloudtech-eyebrow">
                                    توزيع قابل للتتبع
                                </p>
                                <h2 id="task-status-chart-title">
                                    المهام حسب الحالة
                                </h2>
                            </div>
                            <Link href="/tasks">
                                كل المهام
                                <ArrowLeft aria-hidden="true" />
                            </Link>
                        </div>
                        <DistributionChart
                            items={taskStatusDistribution}
                            emptyTitle="لا توجد مهام لعرضها"
                            emptyDescription="تظهر الحالات والنسب بعد إضافة المهام."
                        />
                    </section>

                    <section
                        className="dashboard-panel"
                        aria-labelledby="project-health-chart-title"
                    >
                        <div className="dashboard-panel-head">
                            <div>
                                <p className="cloudtech-eyebrow">
                                    صحة مشتقة من العمل
                                </p>
                                <h2 id="project-health-chart-title">
                                    صحة المشاريع النشطة
                                </h2>
                            </div>
                            <Link href="/projects?activity=active">
                                كل المشاريع
                                <ArrowLeft aria-hidden="true" />
                            </Link>
                        </div>
                        <DistributionChart
                            items={projectHealthDistribution}
                            emptyTitle="لا توجد مشاريع نشطة"
                            emptyDescription="ستظهر الصحة المشتقة عند بدء المشاريع."
                        />
                    </section>
                </div>

                <div className="dashboard-governance-grid">
                    <section
                        className="dashboard-panel"
                        aria-labelledby="workload-title"
                    >
                        <div className="dashboard-panel-head">
                            <div>
                                <p className="cloudtech-eyebrow">
                                    توازن الفريق
                                </p>
                                <h2 id="workload-title">عبء العمل الحالي</h2>
                            </div>
                            <Link href="/team">
                                الفريق
                                <ArrowLeft aria-hidden="true" />
                            </Link>
                        </div>
                        <WorkloadChart workload={workload} />
                    </section>

                    <section
                        className="dashboard-panel"
                        aria-labelledby="risks-title"
                    >
                        <div className="dashboard-panel-head">
                            <div>
                                <p className="cloudtech-eyebrow">الحوكمة</p>
                                <h2 id="risks-title">المخاطر ذات الأولوية</h2>
                            </div>
                            <Link href="/projects?risk=high">
                                سجل المخاطر
                                <ArrowLeft aria-hidden="true" />
                            </Link>
                        </div>
                        <RiskList risks={risks} />
                    </section>

                    <section
                        className="dashboard-panel"
                        aria-labelledby="issues-title"
                    >
                        <div className="dashboard-panel-head">
                            <div>
                                <p className="cloudtech-eyebrow">الحوكمة</p>
                                <h2 id="issues-title">
                                    المشكلات المهمة المفتوحة
                                </h2>
                            </div>
                            <HeartPulse aria-hidden="true" />
                        </div>
                        <IssueList issues={issues} />
                    </section>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'لوحة المتابعة',
            href: dashboard(),
        },
    ],
};

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
    Plus,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import { EditProjectDialog } from '@/components/projects/edit-project-dialog';
import {
    clientName,
    formatDate,
    formatMetric,
    healthLabel,
    numberFormatter,
    priorityLabels,
} from '@/components/projects/project-show-formatters';
import type {
    IconComponent,
    ProjectShowProps,
} from '@/components/projects/project-show-types';
import { ProjectTabContent } from '@/components/projects/project-tab-content';

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
                                    <>
                                        <input
                                            type="hidden"
                                            name="lock_version"
                                            value={project.lock_version ?? 1}
                                        />
                                        <button
                                            type="submit"
                                            className="project-danger-action"
                                            disabled={processing}
                                        >
                                            <Archive aria-hidden="true" />
                                            أرشفة المشروع
                                        </button>
                                    </>
                                )}
                            </Form>
                        )}
                        {canRestore && (
                            <Form
                                action={`/projects/${project.id}/restore`}
                                method="post"
                            >
                                {({ processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="lock_version"
                                            value={project.lock_version ?? 1}
                                        />
                                        <button
                                            type="submit"
                                            className="project-secondary-action"
                                            disabled={processing}
                                        >
                                            <Archive aria-hidden="true" />
                                            استعادة المشروع
                                        </button>
                                    </>
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
                    <ProjectTabContent
                        project={project}
                        metrics={metrics}
                        requirementStatuses={requirementStatuses}
                        activity={activity}
                        canManage={canManage}
                        canCreateTask={canCreateTask}
                        canUploadFile={canUploadFile}
                        governanceArchivedMode={governanceArchivedMode}
                        currentTab={currentTab}
                        progress={progress}
                    />
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

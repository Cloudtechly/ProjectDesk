import { Form, Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    CalendarClock,
    ListChecks,
    RotateCcw,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { PageEmptyState } from '@/components/page-empty-state';
import { PaginationLinks } from '@/components/pagination-links';
import { TaskPageToolbar } from '@/components/tasks/task-page-toolbar';
import {
    collection,
    formatDate,
    KanbanBoard,
    pageLoadedAt,
    priorityLabels,
    RequirementLinks,
    TaskForm,
} from '@/components/tasks/task-workflows';
import type { TasksProps } from '@/components/tasks/task-workflows';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

export default function TasksIndex({
    tasks,
    filters,
    projects = [],
    createProjects = [],
    members = [],
    projectMembers = {},
    projectRequirements = {},
    projectPhases = {},
    statuses = [],
    openCreate = false,
    selectedProjectId,
    editingTask,
    canCreate = false,
}: TasksProps) {
    const rows = collection(tasks);
    const paginator = Array.isArray(tasks) ? undefined : tasks;
    const archivedMode = filters?.archived === '1';
    const view =
        !archivedMode && filters?.view === 'kanban' ? 'kanban' : 'list';
    const [taskFormDirty, setTaskFormDirty] = useState(false);
    const { allowNextNavigation, confirmAndAllowNavigation } =
        useUnsavedChanges(
            (openCreate || Boolean(editingTask)) && taskFormDirty,
            'لديك تغييرات غير محفوظة في المهمة. هل تريد تجاهلها؟',
        );
    const closeTaskEditor = () => {
        if (!confirmAndAllowNavigation()) {
            return;
        }

        setTaskFormDirty(false);
        router.visit('/tasks');
    };
    const hasActiveFilters = [
        'q',
        'project',
        'assignee',
        'status',
        'due',
        'archived',
    ].some((key) => Boolean(filters?.[key]));

    return (
        <>
            <Head title="المهام" />
            <div className="cloudtech-page">
                <TaskPageToolbar
                    filters={filters}
                    projects={projects}
                    members={members}
                    statuses={statuses}
                    archivedMode={archivedMode}
                    view={view}
                    canCreate={canCreate}
                    hasActiveFilters={hasActiveFilters}
                />

                {rows.length === 0 ? (
                    <PageEmptyState
                        eyebrow="مساحة العمل"
                        title={
                            archivedMode
                                ? 'لا توجد مهام مؤرشفة'
                                : 'لا توجد مهام'
                        }
                        description={
                            archivedMode
                                ? 'لا يحتفظ الأرشيف حالياً بأي مهمة.'
                                : 'أضف أول مهمة الآن، أو غيّر الفلاتر لعرض مهام أخرى.'
                        }
                        icon={ListChecks}
                        actionLabel={
                            archivedMode
                                ? 'العودة للمهام النشطة'
                                : canCreate
                                  ? 'إضافة مهمة'
                                  : undefined
                        }
                        actionHref={
                            archivedMode
                                ? '/tasks'
                                : canCreate
                                  ? '/tasks/create'
                                  : undefined
                        }
                        embedded
                    />
                ) : view === 'kanban' ? (
                    <KanbanBoard tasks={rows} statuses={statuses} />
                ) : (
                    <>
                        <div className="cloudtech-table-shell">
                            <table className="cloudtech-data-table tasks-table">
                                <caption className="sr-only">
                                    قائمة جميع المهام
                                </caption>
                                <thead>
                                    <tr>
                                        <th scope="col">المهمة</th>
                                        <th scope="col">المشروع</th>
                                        <th scope="col">المسؤول</th>
                                        <th scope="col">الحالة</th>
                                        <th scope="col">وقت الإسناد</th>
                                        <th scope="col">البداية — النهاية</th>
                                        <th scope="col">الإجراء</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((task) => {
                                        const isOverdue = task.due_at
                                            ? new Date(task.due_at).getTime() <
                                                  pageLoadedAt &&
                                              !['done', 'cancelled'].includes(
                                                  task.status?.semantic || '',
                                              )
                                            : false;

                                        return (
                                            <tr key={task.id}>
                                                <td data-label="المهمة">
                                                    {task.can_update ? (
                                                        <Link
                                                            className="table-primary-cell"
                                                            href={`/tasks/${task.id}/edit`}
                                                        >
                                                            <span
                                                                className="dashboard-code"
                                                                dir="ltr"
                                                            >
                                                                {task.code}
                                                            </span>
                                                            <span>
                                                                <strong>
                                                                    {task.title}
                                                                </strong>
                                                                <small>
                                                                    أولوية{' '}
                                                                    {priorityLabels[
                                                                        task.priority ||
                                                                            ''
                                                                    ] ||
                                                                        task.priority ||
                                                                        'غير محددة'}
                                                                </small>
                                                            </span>
                                                        </Link>
                                                    ) : (
                                                        <span className="table-primary-cell">
                                                            <span
                                                                className="dashboard-code"
                                                                dir="ltr"
                                                            >
                                                                {task.code}
                                                            </span>
                                                            <span>
                                                                <strong>
                                                                    {task.title}
                                                                </strong>
                                                                <small>
                                                                    أولوية{' '}
                                                                    {priorityLabels[
                                                                        task.priority ||
                                                                            ''
                                                                    ] ||
                                                                        task.priority ||
                                                                        'غير محددة'}
                                                                </small>
                                                            </span>
                                                        </span>
                                                    )}
                                                    <RequirementLinks
                                                        requirements={
                                                            task.requirements
                                                        }
                                                    />
                                                </td>
                                                <td data-label="المشروع">
                                                    {task.project ? (
                                                        <Link
                                                            className="table-project-link"
                                                            href={`/projects/${task.project.id}`}
                                                        >
                                                            {task.project.name}
                                                        </Link>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td data-label="المسؤول">
                                                    <span className="table-assignee">
                                                        <UserRound aria-hidden="true" />
                                                        {task.assignee?.name ||
                                                            'غير مسندة'}
                                                    </span>
                                                </td>
                                                <td data-label="الحالة">
                                                    <span
                                                        className="table-status"
                                                        style={
                                                            {
                                                                '--status-color':
                                                                    task.status
                                                                        ?.color ||
                                                                    '#406386',
                                                            } as React.CSSProperties
                                                        }
                                                    >
                                                        {task.status?.label ||
                                                            'دون حالة'}
                                                    </span>
                                                </td>
                                                <td data-label="وقت الإسناد">
                                                    <time
                                                        dateTime={
                                                            task.assigned_at ||
                                                            undefined
                                                        }
                                                    >
                                                        {formatDate(
                                                            task.assigned_at,
                                                        )}
                                                    </time>
                                                </td>
                                                <td data-label="البداية — النهاية">
                                                    <span
                                                        className={`table-date-range ${isOverdue ? 'is-overdue' : ''}`}
                                                    >
                                                        <CalendarClock aria-hidden="true" />
                                                        <span>
                                                            {formatDate(
                                                                task.start_at,
                                                            )}
                                                            <small>
                                                                {formatDate(
                                                                    task.due_at,
                                                                )}
                                                            </small>
                                                        </span>
                                                    </span>
                                                </td>
                                                <td data-label="الإجراء">
                                                    {task.can_archive && (
                                                        <Form
                                                            action={`/tasks/${task.id}/archive`}
                                                            method="post"
                                                            onBefore={() =>
                                                                window.confirm(
                                                                    `هل تريد أرشفة المهمة «${task.title}»؟ ستبقى بياناتها وسجلها محفوظين.`,
                                                                )
                                                            }
                                                        >
                                                            <input
                                                                type="hidden"
                                                                name="lock_version"
                                                                value={
                                                                    task.lock_version ??
                                                                    1
                                                                }
                                                            />
                                                            <button
                                                                type="submit"
                                                                className="table-icon-action is-danger"
                                                                aria-label={`أرشفة المهمة ${task.title}`}
                                                            >
                                                                <Archive aria-hidden="true" />
                                                            </button>
                                                        </Form>
                                                    )}
                                                    {task.can_restore && (
                                                        <Form
                                                            action={`/tasks/${task.id}/restore`}
                                                            method="post"
                                                        >
                                                            <input
                                                                type="hidden"
                                                                name="lock_version"
                                                                value={
                                                                    task.lock_version ??
                                                                    1
                                                                }
                                                            />
                                                            <button
                                                                type="submit"
                                                                className="table-icon-action"
                                                                aria-label={`استعادة المهمة ${task.title}`}
                                                            >
                                                                <RotateCcw aria-hidden="true" />
                                                            </button>
                                                        </Form>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
                <PaginationLinks
                    links={paginator?.links}
                    label="صفحات المهام"
                />
            </div>

            <Dialog
                open={openCreate}
                onOpenChange={(open) => {
                    if (!open) {
                        closeTaskEditor();
                    }
                }}
            >
                <DialogContent className="cloudtech-dialog" dir="rtl">
                    <DialogHeader className="text-start">
                        <p className="cloudtech-eyebrow">مهمة جديدة</p>
                        <DialogTitle>أضف مهمة في وقتها</DialogTitle>
                        <DialogDescription>
                            يسجل النظام وقت الإسناد تلقائياً عند اختيار مسؤول،
                            ويمكنك تعديله قبل الحفظ.
                        </DialogDescription>
                    </DialogHeader>
                    <TaskForm
                        projects={createProjects}
                        members={members}
                        projectMembers={projectMembers}
                        projectRequirements={projectRequirements}
                        projectPhases={projectPhases}
                        statuses={statuses}
                        selectedProjectId={selectedProjectId}
                        onDirtyChange={setTaskFormDirty}
                        onBeforeSubmit={allowNextNavigation}
                    />
                </DialogContent>
            </Dialog>

            <Dialog
                open={Boolean(editingTask)}
                onOpenChange={(open) => {
                    if (!open) {
                        closeTaskEditor();
                    }
                }}
            >
                <DialogContent className="cloudtech-dialog" dir="rtl">
                    <DialogHeader className="text-start">
                        <p className="cloudtech-eyebrow">{editingTask?.code}</p>
                        <DialogTitle>تعديل المهمة</DialogTitle>
                        <DialogDescription>
                            تحديث المسؤول أو الحالة يسجل التغيير ويحافظ على
                            تاريخ الإسناد.
                        </DialogDescription>
                    </DialogHeader>
                    <TaskForm
                        projects={projects}
                        members={members}
                        projectMembers={projectMembers}
                        projectRequirements={projectRequirements}
                        projectPhases={projectPhases}
                        statuses={statuses}
                        task={editingTask}
                        onDirtyChange={setTaskFormDirty}
                        onBeforeSubmit={allowNextNavigation}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}

TasksIndex.layout = { breadcrumbs: [{ title: 'المهام', href: '/tasks' }] };

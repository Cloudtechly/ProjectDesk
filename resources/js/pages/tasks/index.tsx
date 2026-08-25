import { Form, Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    CalendarClock,
    Columns3,
    FileDown,
    LayoutList,
    ListChecks,
    Plus,
    RotateCcw,
    Search,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { PageEmptyState } from '@/components/page-empty-state';
import { PaginationLinks } from '@/components/pagination-links';
import {
    collection,
    formatDate,
    KanbanBoard,
    pageLoadedAt,
    priorityLabels,
    RequirementLinks,
    TaskForm,
    taskViewUrl,
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
import { scopedXlsxUrl } from '@/lib/scoped-export';

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
                <header className="cloudtech-page-head">
                    <div>
                        <p className="cloudtech-eyebrow">مساحة العمل</p>
                        <h1 tabIndex={-1}>جميع المهام</h1>
                        <p>
                            تابع المسؤول ووقت الإسناد والبداية والنهاية والحالة
                            عبر المشاريع.
                        </p>
                    </div>
                    <div className="cloudtech-page-actions">
                        <a
                            className="cloudtech-secondary-action"
                            href={scopedXlsxUrl('tasks', filters)}
                        >
                            <FileDown aria-hidden="true" />
                            تصدير Excel
                        </a>
                        {canCreate && (
                            <Link
                                className="cloudtech-primary-action"
                                href="/tasks/create"
                            >
                                <Plus aria-hidden="true" />
                                إضافة مهمة
                            </Link>
                        )}
                        <Link
                            className="cloudtech-secondary-action"
                            href={archivedMode ? '/tasks' : '/tasks?archived=1'}
                        >
                            {archivedMode ? (
                                <ListChecks aria-hidden="true" />
                            ) : (
                                <Archive aria-hidden="true" />
                            )}
                            {archivedMode ? 'المهام النشطة' : 'أرشيف المهام'}
                        </Link>
                    </div>
                </header>

                <nav
                    className="task-view-switcher"
                    aria-label="طريقة عرض المهام"
                >
                    <Link
                        href={taskViewUrl(filters, 'list')}
                        aria-current={view === 'list' ? 'page' : undefined}
                    >
                        <LayoutList aria-hidden="true" />
                        قائمة
                    </Link>
                    <Link
                        href={taskViewUrl(filters, 'kanban')}
                        aria-current={view === 'kanban' ? 'page' : undefined}
                    >
                        <Columns3 aria-hidden="true" />
                        كانبان
                    </Link>
                </nav>

                <form
                    className="cloudtech-filter-bar task-filters"
                    action="/tasks"
                    method="get"
                >
                    <input type="hidden" name="view" value={view} />
                    {archivedMode && (
                        <input type="hidden" name="archived" value="1" />
                    )}
                    <label className="cloudtech-filter-search">
                        <span className="sr-only">البحث</span>
                        <Search aria-hidden="true" />
                        <input
                            name="q"
                            defaultValue={filters?.q}
                            placeholder="ابحث عن مهمة…"
                        />
                    </label>
                    <label>
                        <span className="sr-only">المشروع</span>
                        <select
                            name="project"
                            defaultValue={filters?.project || ''}
                        >
                            <option value="">كل المشاريع</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">المسؤول</span>
                        <select
                            name="assignee"
                            defaultValue={filters?.assignee || ''}
                        >
                            <option value="">كل المسؤولين</option>
                            <option value="unassigned">غير مسندة</option>
                            {members.map((member) => (
                                <option key={member.id} value={member.id}>
                                    {member.name}
                                </option>
                            ))}
                        </select>
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
                        <span className="sr-only">الموعد</span>
                        <select name="due" defaultValue={filters?.due || ''}>
                            <option value="">كل المواعيد</option>
                            <option value="overdue">المتأخرة فقط</option>
                            <option value="soon">المستحقة خلال 7 أيام</option>
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">ترتيب المهام</span>
                        <select
                            name="sort"
                            defaultValue={filters?.sort || 'due_at'}
                        >
                            <option value="due_at">حسب موعد النهاية</option>
                            <option value="start_at">حسب موعد البداية</option>
                            <option value="assigned_at">حسب وقت الإسناد</option>
                            <option value="priority">حسب الأولوية</option>
                            <option value="title">حسب العنوان</option>
                            <option value="created_at">
                                حسب تاريخ الإنشاء
                            </option>
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
                    <button type="submit">تطبيق الفلاتر</button>
                    {hasActiveFilters && <Link href="/tasks">مسح</Link>}
                </form>

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

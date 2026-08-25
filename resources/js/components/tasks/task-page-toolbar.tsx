import { Link } from '@inertiajs/react';
import {
    Archive,
    Columns3,
    FileDown,
    LayoutList,
    ListChecks,
    Plus,
    Search,
} from 'lucide-react';
import { scopedXlsxUrl } from '@/lib/scoped-export';
import { taskViewUrl } from './task-workflows';
import type { TasksProps } from './task-workflows';

type TaskPageToolbarProps = {
    filters?: TasksProps['filters'];
    projects: NonNullable<TasksProps['projects']>;
    members: NonNullable<TasksProps['members']>;
    statuses: NonNullable<TasksProps['statuses']>;
    archivedMode: boolean;
    view: 'list' | 'kanban';
    canCreate: boolean;
    hasActiveFilters: boolean;
};

export function TaskPageToolbar({
    filters,
    projects,
    members,
    statuses,
    archivedMode,
    view,
    canCreate,
    hasActiveFilters,
}: TaskPageToolbarProps) {
    return (
        <>
            <header className="cloudtech-page-head">
                <div>
                    <p className="cloudtech-eyebrow">مساحة العمل</p>
                    <h1 tabIndex={-1}>جميع المهام</h1>
                    <p>
                        تابع المسؤول ووقت الإسناد والبداية والنهاية والحالة عبر
                        المشاريع.
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

            <nav className="task-view-switcher" aria-label="طريقة عرض المهام">
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
                    <select name="status" defaultValue={filters?.status || ''}>
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
                        <option value="created_at">حسب تاريخ الإنشاء</option>
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
        </>
    );
}

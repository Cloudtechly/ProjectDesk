import { Form, Link, router } from '@inertiajs/react';
import { GripVertical } from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import {
    createLocaleDateTimeFormatter,
    createLocaleNumberFormatter,
} from '@/i18n/formatters';

export type Relation = {
    id: number | string;
    name?: string;
    label?: string;
    color?: string;
    semantic?: string;
};
export type Requirement = {
    id: number | string;
    project_id: number | string;
    code: string;
    title: string;
};
export type Task = {
    id: number | string;
    code: string;
    title: string;
    priority?: string;
    project?: Relation;
    assignee?: Relation | null;
    status?: Relation;
    assigned_at?: string | null;
    start_at?: string | null;
    due_at?: string | null;
    description?: string | null;
    status_id?: number | string;
    project_id?: number | string;
    phase_id?: number | string | null;
    assignee_id?: number | string | null;
    lock_version?: number;
    can_update?: boolean;
    can_update_status?: boolean;
    can_archive?: boolean;
    can_restore?: boolean;
    archived_at?: string | null;
    requirements?: Requirement[];
    assignment_events?: Array<{
        id: number | string;
        assigned_at?: string | null;
        recorded_at?: string | null;
        note?: string | null;
        from_user?: Relation | null;
        to_user?: Relation | null;
        recorded_by?: Relation | null;
    }>;
};
type Paginator<T> = {
    data?: T[];
    links?: Array<{ url?: string | null; label: string; active?: boolean }>;
};
export type TasksProps = {
    tasks?: Paginator<Task> | Task[];
    filters?: Record<string, string>;
    projects?: Relation[];
    createProjects?: Relation[];
    members?: Relation[];
    projectMembers?: Record<string, Relation[]>;
    projectRequirements?: Record<string, Requirement[]>;
    projectPhases?: Record<
        string,
        Array<{ id: number | string; title: string }>
    >;
    statuses?: Relation[];
    openCreate?: boolean;
    selectedProjectId?: number | string | null;
    editingTask?: Task | null;
    canCreate?: boolean;
};

export function taskViewUrl(
    filters: Record<string, string> | undefined,
    view: string,
) {
    const params = new URLSearchParams();

    Object.entries(filters ?? {}).forEach(([key, value]) => {
        if (value && key !== 'view') {
            params.set(key, value);
        }
    });
    params.set('view', view);

    return `/tasks?${params.toString()}`;
}

function requirementUrl(requirement: Requirement) {
    return `/projects/${requirement.project_id}?tab=requirements#requirement-${requirement.id}`;
}

export function RequirementLinks({
    requirements = [],
}: {
    requirements?: Requirement[];
}) {
    if (requirements.length === 0) {
        return null;
    }

    return (
        <span className="task-requirement-links" aria-label="متطلبات مرتبطة">
            {requirements.map((requirement) => (
                <Link key={requirement.id} href={requirementUrl(requirement)}>
                    {requirement.code}
                </Link>
            ))}
        </span>
    );
}

const dateFormatter = createLocaleDateTimeFormatter({
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    timeZone: 'Africa/Tripoli',
});
const numberFormatter = createLocaleNumberFormatter();
export const pageLoadedAt = Date.now();
export const priorityLabels: Record<string, string> = {
    low: 'منخفضة',
    medium: 'متوسطة',
    high: 'عالية',
    critical: 'حرجة',
};

export function collection<T>(value?: Paginator<T> | T[]) {
    return Array.isArray(value) ? value : (value?.data ?? []);
}

export function formatDate(value?: string | null) {
    if (!value) {
        return 'غير محدد';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

function toBusinessDateTime(value?: string | null) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value.slice(0, 16);
    }

    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Africa/Tripoli',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);
    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((item) => item.type === type)?.value ?? '';

    return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}

export function TaskForm({
    projects = [],
    members = [],
    projectMembers = {},
    projectRequirements = {},
    projectPhases = {},
    statuses = [],
    selectedProjectId,
    task,
    onDirtyChange,
    onBeforeSubmit,
}: Pick<
    TasksProps,
    | 'projects'
    | 'members'
    | 'projectMembers'
    | 'projectRequirements'
    | 'projectPhases'
    | 'statuses'
    | 'selectedProjectId'
> & {
    task?: Task | null;
    onDirtyChange?: (dirty: boolean) => void;
    onBeforeSubmit?: () => void;
}) {
    const projectId =
        task?.project_id ?? task?.project?.id ?? selectedProjectId;
    const projectLocked = Boolean(task || selectedProjectId);
    const assigneeId = task?.assignee_id ?? task?.assignee?.id;
    const statusId = task?.status_id ?? task?.status?.id;
    const [selectedProject, setSelectedProject] = useState(
        projectId ? String(projectId) : '',
    );
    const [selectedAssignee, setSelectedAssignee] = useState(
        assigneeId ? String(assigneeId) : '',
    );
    const [assignedAt, setAssignedAt] = useState(
        toBusinessDateTime(task?.assigned_at),
    );
    const [selectedRequirementIds, setSelectedRequirementIds] = useState(
        () => new Set((task?.requirements ?? []).map(({ id }) => String(id))),
    );
    const availableMembers = projectMembers[String(selectedProject)] ?? members;
    const availableRequirements =
        projectRequirements[String(selectedProject)] ?? [];
    const availablePhases = projectPhases[String(selectedProject)] ?? [];
    const selectedRequirements = availableRequirements.filter((requirement) =>
        selectedRequirementIds.has(String(requirement.id)),
    );

    const changeProject = (nextProject: string) => {
        setSelectedProject(nextProject);
        setSelectedRequirementIds(new Set());
        const nextMembers = projectMembers[nextProject] ?? [];

        if (
            selectedAssignee &&
            !nextMembers.some(
                (member) => String(member.id) === selectedAssignee,
            )
        ) {
            setSelectedAssignee('');
            setAssignedAt('');
        }
    };

    const toggleRequirement = (requirementId: string, checked: boolean) => {
        setSelectedRequirementIds((current) => {
            const next = new Set(current);

            if (checked) {
                next.add(requirementId);
            } else {
                next.delete(requirementId);
            }

            return next;
        });
    };

    const changeAssignee = (nextAssignee: string) => {
        setSelectedAssignee(nextAssignee);
        setAssignedAt((current) =>
            nextAssignee
                ? current || toBusinessDateTime(new Date().toISOString())
                : '',
        );
    };

    return (
        <Form
            action={task ? `/tasks/${task.id}` : '/tasks'}
            method={task ? 'put' : 'post'}
            className="cloudtech-form"
            onChange={() => onDirtyChange?.(true)}
            onBefore={onBeforeSubmit}
            onSuccess={() => onDirtyChange?.(false)}
        >
            {({ errors, processing }) => (
                <>
                    {task?.lock_version && (
                        <input
                            type="hidden"
                            name="lock_version"
                            value={task.lock_version}
                        />
                    )}
                    <label>
                        <span>المشروع</span>
                        {projectLocked && (
                            <input
                                type="hidden"
                                name="project_id"
                                value={selectedProject}
                            />
                        )}
                        <select
                            name={projectLocked ? undefined : 'project_id'}
                            required
                            value={selectedProject}
                            disabled={projectLocked}
                            aria-describedby={
                                selectedProjectId
                                    ? 'task-project-locked-help'
                                    : undefined
                            }
                            onChange={(event) =>
                                changeProject(event.target.value)
                            }
                        >
                            <option value="" disabled>
                                اختر المشروع
                            </option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </select>
                        {selectedProjectId && !task && (
                            <small id="task-project-locked-help">
                                تم تثبيت المشروع لأن الإضافة بدأت من مساحة
                                المشروع.
                            </small>
                        )}
                        <InputError message={errors.project_id} />
                    </label>
                    <label>
                        <span>المرحلة الأساسية (اختيارية)</span>
                        <select
                            name="phase_id"
                            defaultValue={
                                task?.phase_id ? String(task.phase_id) : ''
                            }
                        >
                            <option value="">دون مرحلة</option>
                            {availablePhases.map((phase) => (
                                <option key={phase.id} value={phase.id}>
                                    {phase.title}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.phase_id} />
                    </label>
                    <label>
                        <span>عنوان المهمة</span>
                        <input
                            name="title"
                            required
                            placeholder="النتيجة المطلوبة من المهمة"
                            autoFocus
                            defaultValue={task?.title}
                        />
                        <InputError message={errors.title} />
                    </label>
                    <label>
                        <span>الوصف</span>
                        <textarea
                            name="description"
                            rows={3}
                            placeholder="التفاصيل أو معايير الإنجاز"
                            defaultValue={task?.description || ''}
                        />
                        <InputError message={errors.description} />
                    </label>
                    <fieldset className="task-requirements-picker">
                        <legend>متطلبات المشروع (اختياري)</legend>
                        {!selectedProject ? (
                            <p>اختر المشروع لعرض متطلباته.</p>
                        ) : availableRequirements.length === 0 ? (
                            <p>لا توجد متطلبات نشطة لهذا المشروع.</p>
                        ) : (
                            <div>
                                {availableRequirements.map((requirement) => {
                                    const requirementId = String(
                                        requirement.id,
                                    );

                                    return (
                                        <label key={requirement.id}>
                                            <input
                                                type="checkbox"
                                                name="requirement_ids[]"
                                                value={requirement.id}
                                                checked={selectedRequirementIds.has(
                                                    requirementId,
                                                )}
                                                onChange={(event) =>
                                                    toggleRequirement(
                                                        requirementId,
                                                        event.target.checked,
                                                    )
                                                }
                                            />
                                            <span>
                                                <strong dir="ltr">
                                                    {requirement.code}
                                                </strong>{' '}
                                                {requirement.title}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        )}
                        <InputError message={errors.requirement_ids} />
                        {selectedRequirements.length > 0 && (
                            <p className="task-selected-requirements">
                                عرض المتطلبات:{' '}
                                {selectedRequirements.map((requirement) => (
                                    <Link
                                        key={requirement.id}
                                        href={requirementUrl(requirement)}
                                    >
                                        {requirement.code}
                                    </Link>
                                ))}
                            </p>
                        )}
                    </fieldset>
                    <div className="cloudtech-form-grid two-columns">
                        <label>
                            <span>الحالة</span>
                            <select
                                name="status_id"
                                required
                                defaultValue={statusId ? String(statusId) : ''}
                            >
                                <option value="" disabled>
                                    اختر الحالة
                                </option>
                                {statuses.map((status) => (
                                    <option key={status.id} value={status.id}>
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
                                defaultValue={task?.priority || 'medium'}
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
                            <span>المسؤول (اختياري)</span>
                            <select
                                name="assignee_id"
                                value={selectedAssignee}
                                onChange={(event) =>
                                    changeAssignee(event.target.value)
                                }
                            >
                                <option value="">غير مسندة</option>
                                {availableMembers.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.assignee_id} />
                        </label>
                        <label>
                            <span>وقت الإسناد (اختياري)</span>
                            <input
                                name="assigned_at"
                                type="datetime-local"
                                value={assignedAt}
                                disabled={!selectedAssignee}
                                onChange={(event) =>
                                    setAssignedAt(event.target.value)
                                }
                            />
                            <InputError message={errors.assigned_at} />
                        </label>
                        <label className="task-assignment-note">
                            <span>ملاحظة تغيير المسؤول (اختيارية)</span>
                            <textarea
                                name="assignment_note"
                                rows={2}
                                placeholder="سبب الإسناد أو سياقه"
                            />
                            <InputError message={errors.assignment_note} />
                        </label>
                        <label>
                            <span>بداية المهمة</span>
                            <input
                                name="start_at"
                                type="datetime-local"
                                required
                                defaultValue={toBusinessDateTime(
                                    task?.start_at,
                                )}
                            />
                            <InputError message={errors.start_at} />
                        </label>
                        <label>
                            <span>نهاية المهمة</span>
                            <input
                                name="due_at"
                                type="datetime-local"
                                required
                                defaultValue={toBusinessDateTime(task?.due_at)}
                            />
                            <InputError message={errors.due_at} />
                        </label>
                    </div>
                    <button
                        className="cloudtech-primary-action"
                        type="submit"
                        disabled={processing}
                    >
                        {processing
                            ? 'جارٍ الحفظ…'
                            : task
                              ? 'حفظ التعديلات'
                              : 'حفظ المهمة'}
                    </button>
                    {task && (task.assignment_events?.length ?? 0) > 0 && (
                        <section
                            className="assignment-history"
                            aria-labelledby="assignment-history-title"
                        >
                            <h3 id="assignment-history-title">سجل الإسناد</h3>
                            <ol>
                                {task.assignment_events?.map((event) => (
                                    <li key={event.id}>
                                        <span aria-hidden="true" />
                                        <div>
                                            <strong>
                                                {event.from_user?.name ||
                                                    'غير معيّن'}
                                                {' ← '}
                                                {event.to_user?.name ||
                                                    'غير معيّن'}
                                            </strong>
                                            <time
                                                dateTime={
                                                    event.assigned_at ||
                                                    undefined
                                                }
                                            >
                                                وقت الإسناد:{' '}
                                                {formatDate(event.assigned_at)}
                                            </time>
                                            <small>
                                                سجله{' '}
                                                {event.recorded_by?.name ||
                                                    'النظام'}{' '}
                                                في{' '}
                                                {formatDate(event.recorded_at)}
                                            </small>
                                            {event.note && <p>{event.note}</p>}
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </section>
                    )}
                </>
            )}
        </Form>
    );
}

function KanbanStatusControl({
    task,
    statuses,
    busy,
    onUpdate,
}: {
    task: Task;
    statuses: Relation[];
    busy: boolean;
    onUpdate: (task: Task, statusId: string) => void;
}) {
    const currentStatus = String(task.status?.id ?? task.status_id ?? '');
    const [statusId, setStatusId] = useState(currentStatus);

    return (
        <form
            className="kanban-status-form"
            onSubmit={(event) => {
                event.preventDefault();
                onUpdate(task, statusId);
            }}
        >
            <label>
                <span className="sr-only">
                    الحالة البديلة للمهمة {task.title}
                </span>
                <select
                    value={statusId}
                    disabled={busy}
                    onChange={(event) => setStatusId(event.target.value)}
                >
                    {statuses.map((option) => (
                        <option key={option.id} value={option.id}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </label>
            <button type="submit" disabled={busy || statusId === currentStatus}>
                {busy ? 'جارٍ التحديث…' : 'تحديث الحالة'}
            </button>
        </form>
    );
}

export function KanbanBoard({
    tasks,
    statuses,
}: {
    tasks: Task[];
    statuses: Relation[];
}) {
    const pendingTaskIds = useRef(new Set<string>());
    const [updatingTaskIds, setUpdatingTaskIds] = useState(new Set<string>());
    const [draggedTaskId, setDraggedTaskId] = useState<string | null>(null);
    const [dropTargetId, setDropTargetId] = useState<string | null>(null);
    const [announcement, setAnnouncement] = useState('');

    const updateStatus = (task: Task, statusId: string) => {
        const taskId = String(task.id);
        const currentStatusId = String(task.status?.id ?? task.status_id ?? '');

        if (
            !task.can_update_status ||
            currentStatusId === statusId ||
            pendingTaskIds.current.has(taskId)
        ) {
            return;
        }

        pendingTaskIds.current.add(taskId);
        setUpdatingTaskIds((current) => new Set(current).add(taskId));
        router.patch(
            `/tasks/${task.id}/status`,
            { status_id: statusId, lock_version: task.lock_version ?? 1 },
            {
                preserveScroll: true,
                onSuccess: () => {
                    const status = statuses.find(
                        (candidate) => String(candidate.id) === statusId,
                    );
                    setAnnouncement(
                        `تم نقل المهمة ${task.title} إلى ${status?.label ?? 'الحالة الجديدة'}.`,
                    );
                },
                onError: () =>
                    setAnnouncement(`تعذر تحديث حالة المهمة ${task.title}.`),
                onFinish: () => {
                    pendingTaskIds.current.delete(taskId);
                    setUpdatingTaskIds((current) => {
                        const next = new Set(current);
                        next.delete(taskId);

                        return next;
                    });
                },
            },
        );
    };

    return (
        <div
            className="kanban-scroll"
            role="region"
            aria-label="لوحة كانبان للمهام"
            aria-describedby="kanban-drag-help"
            tabIndex={0}
        >
            <p id="kanban-drag-help" className="sr-only">
                يمكن سحب المهمة إلى عمود حالة آخر، أو استخدام قائمة الحالة داخل
                البطاقة كبديل متاح بلوحة المفاتيح.
            </p>
            <p className="sr-only" aria-live="polite">
                {announcement}
            </p>
            <div className="kanban-board">
                {statuses.map((status) => {
                    const statusId = String(status.id);
                    const columnTasks = tasks.filter(
                        (task) =>
                            String(task.status?.id ?? task.status_id) ===
                            statusId,
                    );

                    return (
                        <section
                            className={`kanban-column ${dropTargetId === statusId ? 'is-drop-target' : ''}`}
                            key={status.id}
                            aria-labelledby={`kanban-status-${status.id}`}
                            onDragEnter={(event) => {
                                if (draggedTaskId) {
                                    event.preventDefault();
                                    setDropTargetId(statusId);
                                }
                            }}
                            onDragOver={(event) => {
                                if (draggedTaskId) {
                                    event.preventDefault();
                                    event.dataTransfer.dropEffect = 'move';
                                }
                            }}
                            onDragLeave={(event) => {
                                if (
                                    !event.currentTarget.contains(
                                        event.relatedTarget as Node,
                                    )
                                ) {
                                    setDropTargetId(null);
                                }
                            }}
                            onDrop={(event) => {
                                event.preventDefault();
                                const taskId =
                                    event.dataTransfer.getData(
                                        'text/task-id',
                                    ) || draggedTaskId;
                                const task = tasks.find(
                                    (candidate) =>
                                        String(candidate.id) === taskId,
                                );

                                setDropTargetId(null);
                                setDraggedTaskId(null);

                                if (task) {
                                    updateStatus(task, statusId);
                                }
                            }}
                        >
                            <header
                                style={
                                    {
                                        '--status-color':
                                            status.color || '#406386',
                                    } as React.CSSProperties
                                }
                            >
                                <span
                                    className="kanban-status-mark"
                                    aria-hidden="true"
                                />
                                <h2 id={`kanban-status-${status.id}`}>
                                    {status.label}
                                </h2>
                                <span>
                                    {numberFormatter.format(columnTasks.length)}
                                </span>
                            </header>
                            <div className="kanban-cards">
                                {columnTasks.length === 0 ? (
                                    <p className="kanban-empty">
                                        لا توجد مهام في هذه الحالة.
                                    </p>
                                ) : (
                                    columnTasks.map((task) => {
                                        const taskId = String(task.id);
                                        const busy =
                                            updatingTaskIds.has(taskId);

                                        return (
                                            <article
                                                className="kanban-card"
                                                key={task.id}
                                                aria-busy={busy || undefined}
                                            >
                                                <div className="kanban-card-topline">
                                                    <span
                                                        className="dashboard-code"
                                                        dir="ltr"
                                                    >
                                                        {task.code}
                                                    </span>
                                                    <span>
                                                        {priorityLabels[
                                                            task.priority || ''
                                                        ] || task.priority}
                                                    </span>
                                                    {task.can_update_status && (
                                                        <button
                                                            type="button"
                                                            className="kanban-drag-handle"
                                                            draggable={!busy}
                                                            disabled={busy}
                                                            aria-label={`سحب المهمة ${task.title} لتغيير حالتها`}
                                                            onDragStart={(
                                                                event,
                                                            ) => {
                                                                setDraggedTaskId(
                                                                    taskId,
                                                                );
                                                                event.dataTransfer.effectAllowed =
                                                                    'move';
                                                                event.dataTransfer.setData(
                                                                    'text/task-id',
                                                                    taskId,
                                                                );
                                                                event.dataTransfer.setData(
                                                                    'text/plain',
                                                                    taskId,
                                                                );
                                                            }}
                                                            onDragEnd={() => {
                                                                setDraggedTaskId(
                                                                    null,
                                                                );
                                                                setDropTargetId(
                                                                    null,
                                                                );
                                                            }}
                                                        >
                                                            <GripVertical aria-hidden="true" />
                                                        </button>
                                                    )}
                                                </div>
                                                {task.can_update ? (
                                                    <Link
                                                        href={`/tasks/${task.id}/edit`}
                                                    >
                                                        <h3>{task.title}</h3>
                                                    </Link>
                                                ) : (
                                                    <h3>{task.title}</h3>
                                                )}
                                                <RequirementLinks
                                                    requirements={
                                                        task.requirements
                                                    }
                                                />
                                                <dl>
                                                    <div>
                                                        <dt>المشروع</dt>
                                                        <dd>
                                                            {task.project
                                                                ?.name || '—'}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt>المسؤول</dt>
                                                        <dd>
                                                            {task.assignee
                                                                ?.name ||
                                                                'غير مسندة'}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt>النهاية</dt>
                                                        <dd>
                                                            <time
                                                                dateTime={
                                                                    task.due_at ||
                                                                    undefined
                                                                }
                                                            >
                                                                {formatDate(
                                                                    task.due_at,
                                                                )}
                                                            </time>
                                                        </dd>
                                                    </div>
                                                </dl>
                                                {task.can_update_status && (
                                                    <KanbanStatusControl
                                                        key={`${task.id}-${task.status?.id ?? task.status_id}`}
                                                        task={task}
                                                        statuses={statuses}
                                                        busy={busy}
                                                        onUpdate={updateStatus}
                                                    />
                                                )}
                                            </article>
                                        );
                                    })
                                )}
                            </div>
                        </section>
                    );
                })}
            </div>
        </div>
    );
}

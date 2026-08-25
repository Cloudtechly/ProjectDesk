import { Link, router } from '@inertiajs/react';
import { GripVertical } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    formatDate,
    numberFormatter,
    priorityLabels,
    RequirementLinks,
} from './task-types';
import type { Relation, Task } from './task-types';

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

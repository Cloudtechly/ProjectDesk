import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import {
    formatDate,
    priorityLabels,
    requirementUrl,
    toBusinessDateTime,
} from './task-types';
import type { Task, TasksProps } from './task-types';

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

import { Form, Link } from '@inertiajs/react';
import {
    Archive,
    ArrowRight,
    CheckCircle2,
    Paperclip,
    Plus,
    RotateCcw,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    IssueDialog,
    MeetingDialog,
    MinutesDialog,
    RequirementDialog,
    RiskDialog,
    TimelineDialog,
} from './governance-dialogs';
import { PhasePlanWorkspace } from './phase-plan-workspace';
import { ProjectDocumentsPanel } from './project-documents-panel';
import {
    clientName,
    formatDate,
    formatFileSize,
    formatMetric,
    healthLabel,
    priorityLabels,
} from './project-show-formatters';
import type { IconComponent, ProjectShowProps } from './project-show-types';
import { RequirementTaxonomyPanel } from './requirement-taxonomy-panel';

export type ProjectTab = {
    id: string;
    label: string;
    icon: IconComponent;
    description: string;
};

type ProjectTabContentProps = Pick<
    ProjectShowProps,
    | 'project'
    | 'metrics'
    | 'requirementStatuses'
    | 'activity'
    | 'canManage'
    | 'canCreateTask'
    | 'canUploadFile'
    | 'governanceArchivedMode'
> & {
    currentTab: ProjectTab;
    progress: number;
};

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

export function ProjectTabContent({
    project,
    metrics,
    requirementStatuses = [],
    activity = [],
    canManage = false,
    canCreateTask = false,
    canUploadFile = false,
    governanceArchivedMode = false,
    currentTab,
    progress,
}: ProjectTabContentProps) {
    const activityRows = Array.isArray(activity) ? activity : activity.data;
    const activityPagination = Array.isArray(activity) ? null : activity;
    const isGovernanceTab = [
        'requirements',
        'timeline',
        'meetings',
        'risks',
        'issues',
    ].includes(currentTab.id);
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
                <ProjectDocumentsPanel
                    project={project}
                    canManage={canManage}
                    canUploadFile={canUploadFile}
                />
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

    return renderPanel();
}

import type { ComponentType, SVGProps } from 'react';
import type { RequirementRecord } from './governance-dialogs';

export type Project = {
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

export type RequirementStatus = {
    id: number | string;
    label: string;
};

export type ProjectFile = {
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

export type AttachmentTargetType = 'project' | 'task' | 'requirement';

export type AttachmentTargetOption = {
    id: number;
    code: string;
    title: string;
};

export type RequirementBookVersion = {
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

export type RequirementBookData = {
    id: number | null;
    project_id: number | string;
    title: string | null;
    current_version_id: number | null;
    versions: RequirementBookVersion[];
};

export type Activity = {
    id: number | string;
    action: string;
    subject_type: string;
    subject_id: number | string;
    created_at: string;
    actor?: string | null;
};

export type PaginatedActivity = {
    data: Activity[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url?: string | null;
    next_page_url?: string | null;
};

export type TabPagination = Omit<PaginatedActivity, 'data'>;

export type ProjectMetrics = {
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

export type ProjectShowProps = {
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
    availableMembers?: Array<{
        id: number | string;
        name: string;
        global_role?: string;
    }>;
    canManage?: boolean;
    canArchive?: boolean;
    canRestore?: boolean;
    canCreateTask?: boolean;
    canUploadFile?: boolean;
    governanceArchivedMode?: boolean;
};

export type IconComponent = ComponentType<SVGProps<SVGSVGElement>>;

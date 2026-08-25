import { Link } from '@inertiajs/react';
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

export function requirementUrl(requirement: Requirement) {
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
export const numberFormatter = createLocaleNumberFormatter();
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

export function toBusinessDateTime(value?: string | null) {
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

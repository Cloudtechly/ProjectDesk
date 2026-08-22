import { Head } from '@inertiajs/react';
import {
    BellRing,
    Building2,
    CalendarDays,
    CheckCircle2,
    DatabaseBackup,
    LoaderCircle,
    RotateCcw,
    Save,
    Settings2,
    Workflow,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';

type SettingsData = {
    general: {
        company_name: string | null;
        timezone: string;
    };
    company: {
        display_name: string | null;
        legal_name: string | null;
        email: string | null;
        phone: string | null;
        address: string | null;
        website: string | null;
        tax_number: string | null;
        registration_number: string | null;
        logo_asset: '/brand/cloudtech-logo.svg';
        invoice_prefix: string;
        number_padding: number;
    };
    notifications: {
        enabled: boolean;
        overdue_tasks: boolean;
        upcoming_tasks: boolean;
        meetings: boolean;
        lead_hours: number;
    };
    automatic_backup: {
        enabled: boolean;
        frequency: 'daily' | 'weekly';
        time: string;
        retention_count: number;
    };
    calendar: {
        week_start: number;
        weekend_days: number[];
    };
};

type EditableGroup = keyof SettingsData;
type Section = EditableGroup | 'workflows';
type WorkflowEntity = 'project' | 'task' | 'requirement';

type WorkflowStatus = {
    id: number;
    code: string;
    label: string;
    semantic: string;
    color: string;
    position: number;
    is_active: boolean;
    usage_count: number;
};

const fallbackSettings: SettingsData = {
    general: { company_name: null, timezone: 'Africa/Tripoli' },
    company: {
        display_name: null,
        legal_name: null,
        email: null,
        phone: null,
        address: null,
        website: 'https://cloudtech.ly',
        tax_number: null,
        registration_number: null,
        logo_asset: '/brand/cloudtech-logo.svg',
        invoice_prefix: 'CT-INV',
        number_padding: 3,
    },
    notifications: {
        enabled: true,
        overdue_tasks: true,
        upcoming_tasks: true,
        meetings: true,
        lead_hours: 24,
    },
    automatic_backup: {
        enabled: false,
        frequency: 'daily',
        time: '02:00',
        retention_count: 30,
    },
    calendar: { week_start: 0, weekend_days: [5, 6] },
};

const sections: Array<{
    id: Section;
    label: string;
    description: string;
    icon: typeof Settings2;
}> = [
    {
        id: 'general',
        label: 'الإعدادات العامة',
        description: 'اسم المنشأة والمنطقة الزمنية',
        icon: Building2,
    },
    {
        id: 'company',
        label: 'هوية الشركة والترقيم',
        description: 'بيانات المستندات وأرقامها التسلسلية',
        icon: Building2,
    },
    {
        id: 'workflows',
        label: 'حالات سير العمل',
        description: 'المشاريع والمهام والمتطلبات',
        icon: Workflow,
    },
    {
        id: 'notifications',
        label: 'التنبيهات',
        description: 'المتأخرات والمواعيد والاجتماعات',
        icon: BellRing,
    },
    {
        id: 'calendar',
        label: 'التقويم',
        description: 'بداية الأسبوع وأيام العطلة',
        icon: CalendarDays,
    },
    {
        id: 'automatic_backup',
        label: 'النسخ التلقائي',
        description: 'الجدولة والاحتفاظ بالنسخ',
        icon: DatabaseBackup,
    },
];

const weekdays = [
    'الأحد',
    'الاثنين',
    'الثلاثاء',
    'الأربعاء',
    'الخميس',
    'الجمعة',
    'السبت',
];

const workflowEntityLabels: Record<WorkflowEntity, string> = {
    project: 'المشاريع',
    task: 'المهام',
    requirement: 'المتطلبات',
};

const workflowSemanticLabels: Record<string, string> = {
    open: 'مفتوحة / ابتدائية',
    in_progress: 'قيد التنفيذ',
    done: 'مكتملة',
    cancelled: 'ملغاة',
};

function csrfToken() {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

async function jsonRequest<T>(url: string, init?: RequestInit): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...init?.headers,
        },
    });
    const payload = (await response.json().catch(() => null)) as {
        data?: T;
        message?: string;
        errors?: Record<string, string[]>;
    } | null;

    if (!response.ok) {
        const firstValidationError = payload?.errors
            ? Object.values(payload.errors).flat()[0]
            : undefined;

        throw new Error(
            firstValidationError || payload?.message || 'تعذر إتمام الطلب.',
        );
    }

    return (payload?.data ?? payload) as T;
}

function ToggleField({
    checked,
    onChange,
    label,
    description,
    disabled,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    description: string;
    disabled?: boolean;
}) {
    return (
        <label className="system-settings-toggle">
            <span>
                <strong>{label}</strong>
                <small>{description}</small>
            </span>
            <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.currentTarget.checked)}
            />
        </label>
    );
}

export default function SettingsIndex() {
    const [activeSection, setActiveSection] = useState<Section>('general');
    const [settings, setSettings] = useState<SettingsData>(fallbackSettings);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState<EditableGroup | null>(null);
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');
    const [workflowEntity, setWorkflowEntity] =
        useState<WorkflowEntity>('task');
    const [workflowStatuses, setWorkflowStatuses] = useState<WorkflowStatus[]>(
        [],
    );
    const [workflowLoading, setWorkflowLoading] = useState(false);
    const [workflowSaving, setWorkflowSaving] = useState(false);

    const loadSettings = useCallback(async () => {
        setLoading(true);
        setError('');

        try {
            const data = await jsonRequest<SettingsData>('/system-settings');
            setSettings(data);
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر تحميل الإعدادات.',
            );
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        let cancelled = false;

        void jsonRequest<SettingsData>('/system-settings')
            .then((data) => {
                if (!cancelled) {
                    setSettings(data);
                }
            })
            .catch((requestError: unknown) => {
                if (!cancelled) {
                    setError(
                        requestError instanceof Error
                            ? requestError.message
                            : 'تعذر تحميل الإعدادات.',
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, []);

    function patchGroup<Group extends EditableGroup>(
        group: Group,
        patch: Partial<SettingsData[Group]>,
    ) {
        setSettings((current) => ({
            ...current,
            [group]: { ...current[group], ...patch },
        }));
    }

    async function saveGroup(group: EditableGroup, event: FormEvent) {
        event.preventDefault();
        setSaving(group);
        setError('');
        setNotice('');

        try {
            const saved = await jsonRequest<SettingsData[typeof group]>(
                `/system-settings/${group}`,
                {
                    method: 'PUT',
                    body: JSON.stringify(settings[group]),
                },
            );
            setSettings((current) => ({ ...current, [group]: saved }));
            setNotice('تم حفظ الإعدادات بنجاح.');
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر حفظ الإعدادات.',
            );
        } finally {
            setSaving(null);
        }
    }

    async function resetGroup(group: EditableGroup) {
        if (!window.confirm('هل تريد استعادة القيم الافتراضية لهذا القسم؟')) {
            return;
        }

        setSaving(group);
        setError('');
        setNotice('');

        try {
            const reset = await jsonRequest<SettingsData[typeof group]>(
                `/system-settings/${group}`,
                { method: 'DELETE' },
            );
            setSettings((current) => ({ ...current, [group]: reset }));
            setNotice('تمت استعادة القيم الافتراضية.');
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذرت استعادة القيم الافتراضية.',
            );
        } finally {
            setSaving(null);
        }
    }

    const loadWorkflow = useCallback(async (entity: WorkflowEntity) => {
        setWorkflowLoading(true);
        setError('');

        try {
            const result = await jsonRequest<{
                entity_type: WorkflowEntity;
                statuses: WorkflowStatus[];
            }>(`/settings/workflow-statuses/${entity}`);
            setWorkflowStatuses(result.statuses);
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر تحميل حالات سير العمل.',
            );
        } finally {
            setWorkflowLoading(false);
        }
    }, []);

    function updateWorkflowStatus(id: number, patch: Partial<WorkflowStatus>) {
        setWorkflowStatuses((current) =>
            current.map((status) =>
                status.id === id ? { ...status, ...patch } : status,
            ),
        );
    }

    function moveWorkflowStatus(id: number, direction: -1 | 1) {
        setWorkflowStatuses((current) => {
            const sourceIndex = current.findIndex((status) => status.id === id);
            const targetIndex = sourceIndex + direction;

            if (
                sourceIndex < 0 ||
                targetIndex < 0 ||
                targetIndex >= current.length
            ) {
                return current;
            }

            const reordered = [...current];
            const [moved] = reordered.splice(sourceIndex, 1);
            reordered.splice(targetIndex, 0, moved);

            return reordered.map((status, index) => ({
                ...status,
                position: (index + 1) * 10,
            }));
        });
    }

    async function saveWorkflow() {
        setWorkflowSaving(true);
        setError('');
        setNotice('');

        try {
            const result = await jsonRequest<{
                entity_type: WorkflowEntity;
                statuses: WorkflowStatus[];
            }>(`/settings/workflow-statuses/${workflowEntity}`, {
                method: 'PUT',
                body: JSON.stringify({
                    statuses: workflowStatuses.map((status) => ({
                        id: status.id,
                        label: status.label,
                        semantic: status.semantic,
                        color: status.color,
                        position: status.position,
                        is_active: status.is_active,
                    })),
                }),
            });
            setWorkflowStatuses(result.statuses);
            setNotice('تم حفظ ترتيب حالات سير العمل وتخصيصها.');
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر حفظ حالات سير العمل.',
            );
        } finally {
            setWorkflowSaving(false);
        }
    }

    const formActions = (group: EditableGroup) => (
        <div className="system-settings-actions">
            <button
                className="cloudtech-primary-action"
                type="submit"
                disabled={saving !== null}
            >
                {saving === group ? (
                    <LoaderCircle aria-hidden="true" className="is-spinning" />
                ) : (
                    <Save aria-hidden="true" />
                )}
                {saving === group ? 'جارٍ الحفظ…' : 'حفظ التغييرات'}
            </button>
            <button
                className="cloudtech-secondary-action"
                type="button"
                disabled={saving !== null}
                onClick={() => void resetGroup(group)}
            >
                <RotateCcw aria-hidden="true" />
                القيم الافتراضية
            </button>
        </div>
    );

    return (
        <>
            <Head title="إعدادات النظام" />
            <div className="system-settings-page" aria-busy={loading}>
                <header className="cloudtech-page-head">
                    <div>
                        <span className="cloudtech-eyebrow">مساحة الإدارة</span>
                        <h1 tabIndex={-1}>إعدادات النظام</h1>
                        <p>
                            اضبط سلوك النظام من مكان واحد مع الاحتفاظ بسجل لكل
                            تغيير إداري.
                        </p>
                    </div>
                    <div className="system-settings-health">
                        <Settings2 aria-hidden="true" />
                        <span>
                            <strong>إعدادات الشركة</strong>
                            <small>محفوظة ومطبقة على جميع المستخدمين</small>
                        </span>
                    </div>
                </header>

                <div className="sr-only" aria-live="polite" aria-atomic="true">
                    {notice || error}
                </div>

                {error && (
                    <div className="cloudtech-alert danger" role="alert">
                        <span>{error}</span>
                        <button
                            type="button"
                            onClick={() => void loadSettings()}
                        >
                            إعادة المحاولة
                        </button>
                    </div>
                )}
                {notice && (
                    <div className="cloudtech-alert success" role="status">
                        <CheckCircle2 aria-hidden="true" />
                        {notice}
                    </div>
                )}

                <div className="system-settings-layout">
                    <nav
                        className="system-settings-nav"
                        aria-label="أقسام إعدادات النظام"
                    >
                        {sections.map((section) => {
                            const Icon = section.icon;

                            return (
                                <button
                                    key={section.id}
                                    type="button"
                                    aria-current={
                                        activeSection === section.id
                                            ? 'page'
                                            : undefined
                                    }
                                    onClick={() => {
                                        setActiveSection(section.id);

                                        if (section.id === 'workflows') {
                                            void loadWorkflow(workflowEntity);
                                        }
                                    }}
                                >
                                    <Icon aria-hidden="true" />
                                    <span>
                                        <strong>{section.label}</strong>
                                        <small>{section.description}</small>
                                    </span>
                                </button>
                            );
                        })}
                    </nav>

                    <section className="system-settings-panel" aria-live="off">
                        {loading ? (
                            <div
                                className="system-settings-loading"
                                role="status"
                            >
                                <LoaderCircle
                                    aria-hidden="true"
                                    className="is-spinning"
                                />
                                جارٍ تحميل الإعدادات…
                            </div>
                        ) : (
                            <>
                                {activeSection === 'general' && (
                                    <form
                                        onSubmit={(event) =>
                                            void saveGroup('general', event)
                                        }
                                    >
                                        <header>
                                            <Building2 aria-hidden="true" />
                                            <div>
                                                <h2>الإعدادات العامة</h2>
                                                <p>
                                                    البيانات المشتركة التي تظهر
                                                    في النظام والتقارير.
                                                </p>
                                            </div>
                                        </header>
                                        <div className="cloudtech-form-grid two-columns">
                                            <label>
                                                <span>اسم الشركة</span>
                                                <input
                                                    value={
                                                        settings.general
                                                            .company_name ?? ''
                                                    }
                                                    placeholder="CloudTech"
                                                    onChange={(event) =>
                                                        patchGroup('general', {
                                                            company_name:
                                                                event
                                                                    .currentTarget
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                />
                                            </label>
                                            <label>
                                                <span>المنطقة الزمنية</span>
                                                <select
                                                    value={
                                                        settings.general
                                                            .timezone
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup('general', {
                                                            timezone:
                                                                event
                                                                    .currentTarget
                                                                    .value,
                                                        })
                                                    }
                                                >
                                                    <option value="Africa/Tripoli">
                                                        طرابلس (UTC+02:00)
                                                    </option>
                                                </select>
                                            </label>
                                        </div>
                                        {formActions('general')}
                                    </form>
                                )}

                                {activeSection === 'company' && (
                                    <form
                                        onSubmit={(event) =>
                                            void saveGroup('company', event)
                                        }
                                    >
                                        <header>
                                            <Building2 aria-hidden="true" />
                                            <div>
                                                <h2>هوية الشركة والترقيم</h2>
                                                <p>
                                                    تحفظ هذه البيانات كلقطة
                                                    مستقلة داخل قالب الفاتورة
                                                    عند إنشائه.
                                                </p>
                                            </div>
                                        </header>
                                        <div className="cloudtech-form-grid two-columns">
                                            <label>
                                                <span>الاسم التجاري</span>
                                                <input
                                                    value={
                                                        settings.company
                                                            .display_name ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup('company', {
                                                            display_name:
                                                                event
                                                                    .currentTarget
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                />
                                            </label>
                                            <label>
                                                <span>الاسم القانوني</span>
                                                <input
                                                    value={
                                                        settings.company
                                                            .legal_name ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup('company', {
                                                            legal_name:
                                                                event
                                                                    .currentTarget
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                />
                                            </label>
                                            <label>
                                                <span>البريد الإلكتروني</span>
                                                <input
                                                    type="email"
                                                    dir="ltr"
                                                    value={
                                                        settings.company
                                                            .email ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup('company', {
                                                            email:
                                                                event
                                                                    .currentTarget
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                />
                                            </label>
                                            <label>
                                                <span>الهاتف</span>
                                                <input
                                                    type="tel"
                                                    dir="ltr"
                                                    value={
                                                        settings.company
                                                            .phone ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup('company', {
                                                            phone:
                                                                event
                                                                    .currentTarget
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                />
                                            </label>
                                            <label>
                                                <span>الموقع الإلكتروني</span>
                                                <input
                                                    type="url"
                                                    dir="ltr"
                                                    value={
                                                        settings.company
                                                            .website ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup('company', {
                                                            website:
                                                                event
                                                                    .currentTarget
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                />
                                            </label>
                                            <label>
                                                <span>رقم التسجيل</span>
                                                <input
                                                    dir="ltr"
                                                    value={
                                                        settings.company
                                                            .registration_number ??
                                                        ''
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup('company', {
                                                            registration_number:
                                                                event
                                                                    .currentTarget
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                />
                                            </label>
                                            <label>
                                                <span>الرقم الضريبي</span>
                                                <input
                                                    dir="ltr"
                                                    value={
                                                        settings.company
                                                            .tax_number ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup('company', {
                                                            tax_number:
                                                                event
                                                                    .currentTarget
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                />
                                            </label>
                                            <label className="cloudtech-form-span-two">
                                                <span>العنوان البريدي</span>
                                                <textarea
                                                    rows={3}
                                                    value={
                                                        settings.company
                                                            .address ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup('company', {
                                                            address:
                                                                event
                                                                    .currentTarget
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                />
                                            </label>
                                        </div>
                                        <fieldset>
                                            <legend>
                                                ترقيم قوالب الفواتير
                                            </legend>
                                            <div className="cloudtech-form-grid two-columns">
                                                {(
                                                    [
                                                        [
                                                            'invoice_prefix',
                                                            'قوالب الفواتير',
                                                        ],
                                                    ] as const
                                                ).map(([key, label]) => (
                                                    <label key={key}>
                                                        <span>{label}</span>
                                                        <input
                                                            dir="ltr"
                                                            value={
                                                                settings
                                                                    .company[
                                                                    key
                                                                ]
                                                            }
                                                            pattern="[A-Z0-9]+(?:-[A-Z0-9]+)*"
                                                            onChange={(event) =>
                                                                patchGroup(
                                                                    'company',
                                                                    {
                                                                        [key]: event.currentTarget.value.toUpperCase(),
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </label>
                                                ))}
                                                <label>
                                                    <span>
                                                        عدد خانات التسلسل
                                                    </span>
                                                    <input
                                                        type="number"
                                                        min={2}
                                                        max={8}
                                                        value={
                                                            settings.company
                                                                .number_padding
                                                        }
                                                        onChange={(event) =>
                                                            patchGroup(
                                                                'company',
                                                                {
                                                                    number_padding:
                                                                        Number(
                                                                            event
                                                                                .currentTarget
                                                                                .value,
                                                                        ),
                                                                },
                                                            )
                                                        }
                                                    />
                                                </label>
                                            </div>
                                        </fieldset>
                                        {formActions('company')}
                                    </form>
                                )}

                                {activeSection === 'notifications' && (
                                    <form
                                        onSubmit={(event) =>
                                            void saveGroup(
                                                'notifications',
                                                event,
                                            )
                                        }
                                    >
                                        <header>
                                            <BellRing aria-hidden="true" />
                                            <div>
                                                <h2>التنبيهات الداخلية</h2>
                                                <p>
                                                    حدد الأحداث التي تظهر في
                                                    مركز التنبيهات للمستخدمين.
                                                </p>
                                            </div>
                                        </header>
                                        <div className="system-settings-stack">
                                            <ToggleField
                                                checked={
                                                    settings.notifications
                                                        .enabled
                                                }
                                                label="تفعيل التنبيهات"
                                                description="المفتاح الرئيسي لجميع تنبيهات النظام"
                                                onChange={(enabled) =>
                                                    patchGroup(
                                                        'notifications',
                                                        {
                                                            enabled,
                                                        },
                                                    )
                                                }
                                            />
                                            <ToggleField
                                                checked={
                                                    settings.notifications
                                                        .overdue_tasks
                                                }
                                                disabled={
                                                    !settings.notifications
                                                        .enabled
                                                }
                                                label="المهام المتأخرة"
                                                description="تنبيه عند تجاوز الموعد النهائي لمهمة مفتوحة"
                                                onChange={(overdue_tasks) =>
                                                    patchGroup(
                                                        'notifications',
                                                        {
                                                            overdue_tasks,
                                                        },
                                                    )
                                                }
                                            />
                                            <ToggleField
                                                checked={
                                                    settings.notifications
                                                        .upcoming_tasks
                                                }
                                                disabled={
                                                    !settings.notifications
                                                        .enabled
                                                }
                                                label="المهام القريبة"
                                                description="تنبيه قبل حلول موعد المهمة"
                                                onChange={(upcoming_tasks) =>
                                                    patchGroup(
                                                        'notifications',
                                                        {
                                                            upcoming_tasks,
                                                        },
                                                    )
                                                }
                                            />
                                            <ToggleField
                                                checked={
                                                    settings.notifications
                                                        .meetings
                                                }
                                                disabled={
                                                    !settings.notifications
                                                        .enabled
                                                }
                                                label="الاجتماعات"
                                                description="تنبيه قبل موعد الاجتماع المسجل"
                                                onChange={(meetings) =>
                                                    patchGroup(
                                                        'notifications',
                                                        {
                                                            meetings,
                                                        },
                                                    )
                                                }
                                            />
                                            <label className="system-settings-number-field">
                                                <span>
                                                    <strong>
                                                        مهلة التنبيه
                                                    </strong>
                                                    <small>
                                                        عدد الساعات قبل موعد
                                                        المهمة أو الاجتماع
                                                    </small>
                                                </span>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    max="168"
                                                    dir="ltr"
                                                    value={
                                                        settings.notifications
                                                            .lead_hours
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup(
                                                            'notifications',
                                                            {
                                                                lead_hours:
                                                                    Number(
                                                                        event
                                                                            .currentTarget
                                                                            .value,
                                                                    ),
                                                            },
                                                        )
                                                    }
                                                />
                                            </label>
                                        </div>
                                        {formActions('notifications')}
                                    </form>
                                )}

                                {activeSection === 'calendar' && (
                                    <form
                                        onSubmit={(event) =>
                                            void saveGroup('calendar', event)
                                        }
                                    >
                                        <header>
                                            <CalendarDays aria-hidden="true" />
                                            <div>
                                                <h2>تقويم العمل</h2>
                                                <p>
                                                    تؤثر هذه القيم في الجدول
                                                    الأسبوعي وحساب الأيام
                                                    المعروضة.
                                                </p>
                                            </div>
                                        </header>
                                        <label className="system-settings-select-field">
                                            <span>بداية الأسبوع</span>
                                            <select
                                                value={
                                                    settings.calendar.week_start
                                                }
                                                onChange={(event) =>
                                                    patchGroup('calendar', {
                                                        week_start: Number(
                                                            event.currentTarget
                                                                .value,
                                                        ),
                                                    })
                                                }
                                            >
                                                {weekdays.map((day, index) => (
                                                    <option
                                                        key={day}
                                                        value={index}
                                                    >
                                                        {day}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        <fieldset className="system-settings-weekdays">
                                            <legend>
                                                أيام العطلة الأسبوعية
                                            </legend>
                                            <div>
                                                {weekdays.map((day, index) => (
                                                    <label key={day}>
                                                        <input
                                                            type="checkbox"
                                                            checked={settings.calendar.weekend_days.includes(
                                                                index,
                                                            )}
                                                            onChange={(
                                                                event,
                                                            ) => {
                                                                const selected =
                                                                    event
                                                                        .currentTarget
                                                                        .checked
                                                                        ? [
                                                                              ...settings
                                                                                  .calendar
                                                                                  .weekend_days,
                                                                              index,
                                                                          ]
                                                                        : settings.calendar.weekend_days.filter(
                                                                              (
                                                                                  value,
                                                                              ) =>
                                                                                  value !==
                                                                                  index,
                                                                          );
                                                                patchGroup(
                                                                    'calendar',
                                                                    {
                                                                        weekend_days:
                                                                            [
                                                                                ...new Set(
                                                                                    selected,
                                                                                ),
                                                                            ].sort(),
                                                                    },
                                                                );
                                                            }}
                                                        />
                                                        <span>{day}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        </fieldset>
                                        {formActions('calendar')}
                                    </form>
                                )}

                                {activeSection === 'automatic_backup' && (
                                    <form
                                        onSubmit={(event) =>
                                            void saveGroup(
                                                'automatic_backup',
                                                event,
                                            )
                                        }
                                    >
                                        <header>
                                            <DatabaseBackup aria-hidden="true" />
                                            <div>
                                                <h2>
                                                    النسخ الاحتياطي التلقائي
                                                </h2>
                                                <p>
                                                    حدد موعد النسخ وعدد النسخ
                                                    التي يحتفظ بها النظام.
                                                </p>
                                            </div>
                                        </header>
                                        <div className="system-settings-stack">
                                            <ToggleField
                                                checked={
                                                    settings.automatic_backup
                                                        .enabled
                                                }
                                                label="تفعيل النسخ التلقائي"
                                                description="ينفذ النسخ عبر مجدول النظام في الوقت المحدد"
                                                onChange={(enabled) =>
                                                    patchGroup(
                                                        'automatic_backup',
                                                        { enabled },
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="cloudtech-form-grid three-columns">
                                            <label>
                                                <span>التكرار</span>
                                                <select
                                                    value={
                                                        settings
                                                            .automatic_backup
                                                            .frequency
                                                    }
                                                    disabled={
                                                        !settings
                                                            .automatic_backup
                                                            .enabled
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup(
                                                            'automatic_backup',
                                                            {
                                                                frequency: event
                                                                    .currentTarget
                                                                    .value as
                                                                    | 'daily'
                                                                    | 'weekly',
                                                            },
                                                        )
                                                    }
                                                >
                                                    <option value="daily">
                                                        يومي
                                                    </option>
                                                    <option value="weekly">
                                                        أسبوعي
                                                    </option>
                                                </select>
                                            </label>
                                            <label>
                                                <span>وقت التنفيذ</span>
                                                <input
                                                    type="time"
                                                    dir="ltr"
                                                    disabled={
                                                        !settings
                                                            .automatic_backup
                                                            .enabled
                                                    }
                                                    value={
                                                        settings
                                                            .automatic_backup
                                                            .time
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup(
                                                            'automatic_backup',
                                                            {
                                                                time: event
                                                                    .currentTarget
                                                                    .value,
                                                            },
                                                        )
                                                    }
                                                />
                                            </label>
                                            <label>
                                                <span>عدد النسخ المحفوظة</span>
                                                <input
                                                    type="number"
                                                    dir="ltr"
                                                    min="1"
                                                    max="90"
                                                    disabled={
                                                        !settings
                                                            .automatic_backup
                                                            .enabled
                                                    }
                                                    value={
                                                        settings
                                                            .automatic_backup
                                                            .retention_count
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup(
                                                            'automatic_backup',
                                                            {
                                                                retention_count:
                                                                    Number(
                                                                        event
                                                                            .currentTarget
                                                                            .value,
                                                                    ),
                                                            },
                                                        )
                                                    }
                                                />
                                            </label>
                                        </div>
                                        <p className="system-settings-note">
                                            النسخ اليدوي والاستعادة متاحان من
                                            مركز البيانات مع سجل تدقيق مستقل.
                                        </p>
                                        {formActions('automatic_backup')}
                                    </form>
                                )}

                                {activeSection === 'workflows' && (
                                    <div className="system-settings-workflows">
                                        <header>
                                            <Workflow aria-hidden="true" />
                                            <div>
                                                <h2>حالات سير العمل</h2>
                                                <p>
                                                    غيّر الاسم واللون والترتيب؛
                                                    أما الدلالة المنطقية فتبقى
                                                    ثابتة لحماية التقارير.
                                                </p>
                                            </div>
                                        </header>
                                        <div
                                            className="system-settings-workflow-tabs"
                                            role="tablist"
                                            aria-label="نوع سير العمل"
                                        >
                                            {(
                                                Object.keys(
                                                    workflowEntityLabels,
                                                ) as WorkflowEntity[]
                                            ).map((entity) => (
                                                <button
                                                    key={entity}
                                                    type="button"
                                                    role="tab"
                                                    aria-selected={
                                                        workflowEntity ===
                                                        entity
                                                    }
                                                    tabIndex={
                                                        workflowEntity ===
                                                        entity
                                                            ? 0
                                                            : -1
                                                    }
                                                    onKeyDown={(event) => {
                                                        const tabs = Array.from(
                                                            event.currentTarget.parentElement?.querySelectorAll<HTMLButtonElement>(
                                                                '[role="tab"]',
                                                            ) ?? [],
                                                        );
                                                        const current =
                                                            tabs.indexOf(
                                                                event.currentTarget,
                                                            );
                                                        let next = current;

                                                        if (
                                                            event.key ===
                                                            'ArrowLeft'
                                                        ) {
                                                            next =
                                                                (current + 1) %
                                                                tabs.length;
                                                        } else if (
                                                            event.key ===
                                                            'ArrowRight'
                                                        ) {
                                                            next =
                                                                (current -
                                                                    1 +
                                                                    tabs.length) %
                                                                tabs.length;
                                                        } else if (
                                                            event.key === 'Home'
                                                        ) {
                                                            next = 0;
                                                        } else if (
                                                            event.key === 'End'
                                                        ) {
                                                            next =
                                                                tabs.length - 1;
                                                        } else {
                                                            return;
                                                        }

                                                        event.preventDefault();
                                                        tabs[next]?.focus();
                                                        tabs[next]?.click();
                                                    }}
                                                    onClick={() => {
                                                        setWorkflowEntity(
                                                            entity,
                                                        );
                                                        void loadWorkflow(
                                                            entity,
                                                        );
                                                    }}
                                                >
                                                    {
                                                        workflowEntityLabels[
                                                            entity
                                                        ]
                                                    }
                                                </button>
                                            ))}
                                        </div>
                                        {workflowLoading ? (
                                            <div
                                                className="system-settings-loading"
                                                role="status"
                                            >
                                                <LoaderCircle
                                                    aria-hidden="true"
                                                    className="is-spinning"
                                                />
                                                جارٍ تحميل الحالات…
                                            </div>
                                        ) : (
                                            <div className="system-settings-status-list">
                                                {workflowStatuses.map(
                                                    (status, index) => (
                                                        <article
                                                            key={status.id}
                                                        >
                                                            <span
                                                                className="system-settings-status-dot"
                                                                style={{
                                                                    backgroundColor:
                                                                        status.color,
                                                                }}
                                                                aria-hidden="true"
                                                            />
                                                            <label>
                                                                <span>
                                                                    اسم الحالة
                                                                </span>
                                                                <input
                                                                    value={
                                                                        status.label
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateWorkflowStatus(
                                                                            status.id,
                                                                            {
                                                                                label: event
                                                                                    .currentTarget
                                                                                    .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </label>
                                                            <label>
                                                                <span>
                                                                    التصنيف
                                                                    الدلالي
                                                                </span>
                                                                <select
                                                                    value={
                                                                        status.semantic
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateWorkflowStatus(
                                                                            status.id,
                                                                            {
                                                                                semantic:
                                                                                    event
                                                                                        .currentTarget
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    {Object.entries(
                                                                        workflowSemanticLabels,
                                                                    ).map(
                                                                        ([
                                                                            value,
                                                                            label,
                                                                        ]) => (
                                                                            <option
                                                                                key={
                                                                                    value
                                                                                }
                                                                                value={
                                                                                    value
                                                                                }
                                                                            >
                                                                                {
                                                                                    label
                                                                                }
                                                                            </option>
                                                                        ),
                                                                    )}
                                                                </select>
                                                            </label>
                                                            <label className="system-settings-color-field">
                                                                <span>
                                                                    اللون
                                                                </span>
                                                                <input
                                                                    type="color"
                                                                    value={
                                                                        status.color
                                                                    }
                                                                    aria-label={`لون حالة ${status.label}`}
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateWorkflowStatus(
                                                                            status.id,
                                                                            {
                                                                                color: event
                                                                                    .currentTarget
                                                                                    .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </label>
                                                            <span className="system-settings-status-meta">
                                                                <bdi dir="ltr">
                                                                    {
                                                                        status.code
                                                                    }
                                                                </bdi>
                                                                <small>
                                                                    مستخدمة في{' '}
                                                                    {
                                                                        status.usage_count
                                                                    }{' '}
                                                                    من السجلات
                                                                </small>
                                                            </span>
                                                            <label className="system-settings-status-active">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={
                                                                        status.is_active
                                                                    }
                                                                    disabled={
                                                                        status.usage_count >
                                                                        0
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateWorkflowStatus(
                                                                            status.id,
                                                                            {
                                                                                is_active:
                                                                                    event
                                                                                        .currentTarget
                                                                                        .checked,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                                نشطة
                                                            </label>
                                                            <div className="system-settings-status-order">
                                                                <button
                                                                    type="button"
                                                                    disabled={
                                                                        index ===
                                                                        0
                                                                    }
                                                                    aria-label={`نقل ${status.label} للأعلى`}
                                                                    onClick={() =>
                                                                        moveWorkflowStatus(
                                                                            status.id,
                                                                            -1,
                                                                        )
                                                                    }
                                                                >
                                                                    ↑
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    disabled={
                                                                        index ===
                                                                        workflowStatuses.length -
                                                                            1
                                                                    }
                                                                    aria-label={`نقل ${status.label} للأسفل`}
                                                                    onClick={() =>
                                                                        moveWorkflowStatus(
                                                                            status.id,
                                                                            1,
                                                                        )
                                                                    }
                                                                >
                                                                    ↓
                                                                </button>
                                                            </div>
                                                        </article>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                        <div className="system-settings-actions">
                                            <button
                                                type="button"
                                                className="cloudtech-primary-action"
                                                disabled={
                                                    workflowSaving ||
                                                    workflowLoading
                                                }
                                                onClick={() =>
                                                    void saveWorkflow()
                                                }
                                            >
                                                {workflowSaving ? (
                                                    <LoaderCircle
                                                        aria-hidden="true"
                                                        className="is-spinning"
                                                    />
                                                ) : (
                                                    <Save aria-hidden="true" />
                                                )}
                                                {workflowSaving
                                                    ? 'جارٍ الحفظ…'
                                                    : 'حفظ الحالات'}
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

SettingsIndex.layout = {
    breadcrumbs: [{ title: 'الإعدادات', href: '/settings' }],
};

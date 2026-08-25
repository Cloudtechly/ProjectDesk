import { Head } from '@inertiajs/react';
import {
    BellRing,
    Bot,
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
import type { Dispatch, FormEvent, SetStateAction } from 'react';
import type {
    EditableGroup,
    LocalEngineStatus,
    Section,
    SettingsData,
    SettingsSection,
    WorkflowEntity,
    WorkflowStatus,
} from './settings-contracts';

const sections: SettingsSection[] = [
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
        id: 'local_ai',
        label: 'الذكاء الاصطناعي المحلي',
        description: 'Ollama وOCR دون خدمات سحابية',
        icon: Bot,
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

type SettingsWorkspaceProps = {
    activeSection: Section;
    setActiveSection: Dispatch<SetStateAction<Section>>;
    settings: SettingsData;
    loading: boolean;
    saving: EditableGroup | null;
    notice: string;
    error: string;
    patchGroup: <Group extends EditableGroup>(
        group: Group,
        patch: Partial<SettingsData[Group]>,
    ) => void;
    saveGroup: (group: EditableGroup, event: FormEvent) => Promise<void>;
    resetGroup: (group: EditableGroup) => Promise<void>;
    loadSettings: () => Promise<void>;
    workflowEntity: WorkflowEntity;
    setWorkflowEntity: Dispatch<SetStateAction<WorkflowEntity>>;
    workflowStatuses: WorkflowStatus[];
    workflowLoading: boolean;
    workflowSaving: boolean;
    loadWorkflow: (entity: WorkflowEntity) => Promise<void>;
    updateWorkflowStatus: (id: number, patch: Partial<WorkflowStatus>) => void;
    moveWorkflowStatus: (id: number, direction: -1 | 1) => void;
    saveWorkflow: () => Promise<void>;
    engineStatus: LocalEngineStatus | null;
    engineLoading: boolean;
    testLocalEngine: () => Promise<void>;
};

export function SettingsWorkspace(props: SettingsWorkspaceProps) {
    const {
        activeSection,
        setActiveSection,
        settings,
        loading,
        saving,
        notice,
        error,
        patchGroup,
        saveGroup,
        resetGroup,
        loadSettings,
        workflowEntity,
        setWorkflowEntity,
        workflowStatuses,
        workflowLoading,
        workflowSaving,
        loadWorkflow,
        updateWorkflowStatus,
        moveWorkflowStatus,
        saveWorkflow,
        engineStatus,
        engineLoading,
        testLocalEngine,
    } = props;

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
                                            <ToggleField
                                                checked={
                                                    settings.notifications
                                                        .milestones
                                                }
                                                disabled={
                                                    !settings.notifications
                                                        .enabled
                                                }
                                                label="معالم التسليم"
                                                description="تنبيه قبل 14 و7 و3 أيام وعند التأخر"
                                                onChange={(milestones) =>
                                                    patchGroup(
                                                        'notifications',
                                                        { milestones },
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

                                {activeSection === 'local_ai' && (
                                    <form
                                        onSubmit={(event) =>
                                            void saveGroup('local_ai', event)
                                        }
                                    >
                                        <header>
                                            <Bot aria-hidden="true" />
                                            <div>
                                                <h2>الذكاء الاصطناعي المحلي</h2>
                                                <p>
                                                    تحليل PDF وDOCX على هذا
                                                    الجهاز عبر Ollama؛ لا توجد
                                                    مفاتيح API أو خدمات سحابية.
                                                </p>
                                            </div>
                                        </header>
                                        <div className="system-settings-stack">
                                            <ToggleField
                                                checked={
                                                    settings.local_ai.enabled
                                                }
                                                onChange={(enabled) =>
                                                    patchGroup('local_ai', {
                                                        enabled,
                                                    })
                                                }
                                                label="تفعيل التحليل المحلي"
                                                description="يسمح لمدير المشروع ببدء التحليل والمراجعة البشرية."
                                            />
                                            <ToggleField
                                                checked={
                                                    settings.local_ai
                                                        .auto_analyze
                                                }
                                                disabled={
                                                    !settings.local_ai.enabled
                                                }
                                                onChange={(auto_analyze) =>
                                                    patchGroup('local_ai', {
                                                        auto_analyze,
                                                    })
                                                }
                                                label="التحليل التلقائي بعد الرفع"
                                                description="يبدأ بعد اجتياز فحص الملف، ويمكن إيقافه أو إعادة تشغيله."
                                            />
                                            <label>
                                                <span>نموذج Ollama</span>
                                                <input
                                                    dir="ltr"
                                                    value={
                                                        settings.local_ai.model
                                                    }
                                                    onChange={(event) =>
                                                        patchGroup('local_ai', {
                                                            model: event
                                                                .currentTarget
                                                                .value,
                                                        })
                                                    }
                                                    pattern="[a-zA-Z0-9._:-]+"
                                                    required
                                                />
                                            </label>
                                            <div className="system-settings-grid">
                                                <label>
                                                    <span>حجم السياق</span>
                                                    <select
                                                        value={
                                                            settings.local_ai
                                                                .context_size
                                                        }
                                                        onChange={(event) =>
                                                            patchGroup(
                                                                'local_ai',
                                                                {
                                                                    context_size:
                                                                        Number(
                                                                            event
                                                                                .currentTarget
                                                                                .value,
                                                                        ),
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <option value="4096">
                                                            4096
                                                        </option>
                                                        <option value="8192">
                                                            8192 (موصى به)
                                                        </option>
                                                        <option value="16384">
                                                            16384
                                                        </option>
                                                    </select>
                                                </label>
                                                <label>
                                                    <span>حد الصفحات</span>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max="300"
                                                        value={
                                                            settings.local_ai
                                                                .max_pages
                                                        }
                                                        onChange={(event) =>
                                                            patchGroup(
                                                                'local_ai',
                                                                {
                                                                    max_pages:
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
                                        </div>
                                        <section
                                            className="local-engine-status"
                                            aria-live="polite"
                                        >
                                            <header>
                                                <h3>حالة المكونات المحلية</h3>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        void testLocalEngine()
                                                    }
                                                    disabled={engineLoading}
                                                >
                                                    {engineLoading
                                                        ? 'جارٍ الاختبار…'
                                                        : 'اختبار محلي'}
                                                </button>
                                            </header>
                                            <dl>
                                                <div>
                                                    <dt>Ollama</dt>
                                                    <dd
                                                        data-ok={
                                                            engineStatus?.ollama
                                                                ?.available
                                                        }
                                                    >
                                                        {engineStatus?.ollama
                                                            ?.available
                                                            ? 'متصل على 127.0.0.1:11434'
                                                            : 'غير متصل'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt>النموذج</dt>
                                                    <dd
                                                        data-ok={
                                                            engineStatus?.model_installed
                                                        }
                                                    >
                                                        {engineStatus?.model_installed
                                                            ? 'مثبت'
                                                            : 'غير مثبت'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt>GPU</dt>
                                                    <dd
                                                        data-ok={
                                                            engineStatus?.gpu
                                                                ?.available
                                                        }
                                                    >
                                                        {engineStatus?.gpu
                                                            ?.available
                                                            ? `${engineStatus.gpu.name ?? 'NVIDIA'} · ${engineStatus.gpu.memory_free_mb ?? '—'}MB متاح`
                                                            : 'غير مكتشف'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt>Poppler</dt>
                                                    <dd
                                                        data-ok={
                                                            engineStatus
                                                                ?.extractors
                                                                ?.poppler
                                                        }
                                                    >
                                                        {engineStatus
                                                            ?.extractors
                                                            ?.poppler
                                                            ? 'جاهز'
                                                            : 'غير مثبت'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt>Tesseract ara+eng</dt>
                                                    <dd
                                                        data-ok={
                                                            engineStatus
                                                                ?.extractors
                                                                ?.tesseract &&
                                                            engineStatus
                                                                ?.extractors
                                                                ?.ocr_languages
                                                                ?.ara &&
                                                            engineStatus
                                                                ?.extractors
                                                                ?.ocr_languages
                                                                ?.eng
                                                        }
                                                    >
                                                        {engineStatus
                                                            ?.extractors
                                                            ?.tesseract &&
                                                        engineStatus?.extractors
                                                            ?.ocr_languages
                                                            ?.ara &&
                                                        engineStatus?.extractors
                                                            ?.ocr_languages?.eng
                                                            ? 'جاهز'
                                                            : 'ناقص'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt>الاتصال السحابي</dt>
                                                    <dd data-ok>معطل دائمًا</dd>
                                                </div>
                                            </dl>
                                        </section>
                                        {formActions('local_ai')}
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

import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useUnsavedDialog } from '@/hooks/use-unsaved-changes';

type Option = {
    id: number | string;
    name?: string;
    label?: string;
    contacts?: Array<{ id: number | string; name: string }>;
};
type Phase = {
    key: string;
    title: string;
    starts_at: string;
    ends_at: string;
    status: string;
    weight_percent: number;
    completion_criteria: string;
    milestones: Milestone[];
};
type Milestone = {
    key: string;
    title: string;
    date: string;
    status: string;
    is_gate: boolean;
};
type Task = {
    key: string;
    title: string;
    status_id: string;
    priority: string;
    phase_index: number;
    assignee_id: string;
    start_at: string;
    due_at: string;
};
type Risk = {
    key: string;
    title: string;
    probability: number;
    impact: number;
    status: string;
};
type Issue = { key: string; title: string; severity: string; status: string };

const steps = [
    'المشروع والفريق',
    'تواريخ الانتقال',
    'المراحل والأوزان',
    'المعالم',
    'المهام المفتوحة',
    'المخاطر والمشكلات',
    'المراجعة والاعتماد',
];
const phaseStatuses = [
    ['planned', 'مخطط'],
    ['in_progress', 'قيد التنفيذ'],
    ['completed', 'مكتمل تاريخيًا'],
    ['cancelled', 'ملغى'],
];

function withoutKey<T extends { key: string }>(value: T): Omit<T, 'key'> {
    const { key, ...record } = value;

    void key;

    return record;
}

function newPhase(): Phase {
    return {
        key: crypto.randomUUID(),
        title: '',
        starts_at: '',
        ends_at: '',
        status: 'planned',
        weight_percent: 100,
        completion_criteria: '',
        milestones: [],
    };
}

export function ExistingProjectDialog({
    statuses,
    taskStatuses,
    clients,
    members,
}: {
    statuses: Option[];
    taskStatuses: Option[];
    clients: Option[];
    members: Option[];
}) {
    const {
        allowNextNavigation,
        closeAfterSuccess,
        markDirty,
        onOpenChange,
        open,
    } = useUnsavedDialog(
        false,
        'لديك تغييرات غير محفوظة في إدخال المشروع القائم. هل تريد تجاهلها؟',
    );
    const [step, setStep] = useState(1);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [project, setProject] = useState({
        code: '',
        name: '',
        description: '',
        client_id: '',
        primary_contact_id: '',
        manager_id: '',
        status_id: '',
        priority: 'medium',
        start_date: '',
        end_date: '',
    });
    const [transitionedAt, setTransitionedAt] = useState('');
    const [memberRoles, setMemberRoles] = useState<Record<string, string>>({});
    const [phases, setPhases] = useState<Phase[]>([newPhase()]);
    const [tasks, setTasks] = useState<Task[]>([]);
    const [risks, setRisks] = useState<Risk[]>([]);
    const [issues, setIssues] = useState<Issue[]>([]);
    const contacts =
        clients.find((client) => String(client.id) === project.client_id)
            ?.contacts ?? [];
    const weightTotal = phases
        .filter((phase) => phase.status !== 'cancelled')
        .reduce((sum, phase) => sum + Number(phase.weight_percent), 0);
    const selectedMembers = useMemo(
        () => members.filter((member) => memberRoles[String(member.id)]),
        [members, memberRoles],
    );

    function updatePhase(index: number, patch: Partial<Phase>) {
        setPhases((current) =>
            current.map((phase, item) =>
                item === index ? { ...phase, ...patch } : phase,
            ),
        );
    }
    function next() {
        const panel = document.querySelector<HTMLElement>(
            `.existing-project-step[data-step="${step}"]`,
        );
        const invalid = Array.from(
            panel?.querySelectorAll<
                HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
            >('input,select,textarea') ?? [],
        ).find((field) => !field.checkValidity());

        if (invalid) {
            invalid.reportValidity();
            invalid.focus();

            return;
        }

        if (step === 3 && weightTotal !== 100) {
            setMessage('يجب أن يكون مجموع أوزان المراحل غير الملغاة 100٪.');

            return;
        }

        setMessage('');
        setStep((value) => Math.min(7, value + 1));
    }

    function submit() {
        if (weightTotal !== 100) {
            setStep(3);
            setMessage('مجموع الأوزان يجب أن يساوي 100٪.');

            return;
        }

        setBusy(true);
        setMessage('');
        allowNextNavigation();
        router.post(
            '/projects/existing',
            {
                project,
                transitioned_at: transitionedAt,
                members: selectedMembers.map((member) => ({
                    id: member.id,
                    role: memberRoles[String(member.id)],
                })),
                phases: phases.map((phase) => ({
                    ...withoutKey(phase),
                    milestones: phase.milestones.map(withoutKey),
                })),
                tasks: tasks.map((task) => ({
                    ...withoutKey(task),
                    assignee_id: task.assignee_id || null,
                })),
                risks: risks.map(withoutKey),
                issues: issues.map(withoutKey),
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    setMessage(
                        Object.values(errors)[0] ?? 'تعذر إدخال المشروع.',
                    );
                    setBusy(false);
                },
                onSuccess: () => {
                    setBusy(false);
                    closeAfterSuccess();
                },
                onFinish: () => setBusy(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogTrigger asChild>
                <button className="cloudtech-secondary-action" type="button">
                    <Plus aria-hidden="true" /> إدخال مشروع قائم
                </button>
            </DialogTrigger>
            <DialogContent
                className="cloudtech-dialog existing-project-dialog"
                dir="rtl"
            >
                <DialogHeader className="text-start">
                    <p className="cloudtech-eyebrow">مشروع بدأ قبل النظام</p>
                    <DialogTitle>Wizard إدخال الحالة الفعلية</DialogTitle>
                    <DialogDescription>
                        ينشئ المشروع وخطته وسجلاته ولقطة انتقال غير قابلة
                        للتعديل داخل معاملة واحدة.
                    </DialogDescription>
                </DialogHeader>
                <ol
                    className="existing-wizard-steps"
                    aria-label="خطوات إدخال مشروع قائم"
                >
                    {steps.map((label, index) => (
                        <li
                            key={label}
                            aria-current={
                                step === index + 1 ? 'step' : undefined
                            }
                            className={step > index + 1 ? 'is-complete' : ''}
                        >
                            <span>{index + 1}</span>
                            <small>{label}</small>
                        </li>
                    ))}
                </ol>
                {message && (
                    <div className="cloudtech-alert danger" role="alert">
                        {message}
                    </div>
                )}

                <section
                    hidden={step !== 1}
                    className="existing-project-step"
                    data-step="1"
                >
                    <div className="cloudtech-form-grid two-columns">
                        <label>
                            <span>رمز المشروع</span>
                            <input
                                required
                                dir="ltr"
                                value={project.code}
                                onChange={(e) => {
                                    markDirty();
                                    setProject({
                                        ...project,
                                        code: e.target.value,
                                    });
                                }}
                            />
                        </label>
                        <label>
                            <span>اسم المشروع</span>
                            <input
                                required
                                value={project.name}
                                onChange={(e) => {
                                    markDirty();
                                    setProject({
                                        ...project,
                                        name: e.target.value,
                                    });
                                }}
                            />
                        </label>
                        <label>
                            <span>العميل</span>
                            <select
                                value={project.client_id}
                                onChange={(e) =>
                                    setProject({
                                        ...project,
                                        client_id: e.target.value,
                                        primary_contact_id: '',
                                    })
                                }
                            >
                                <option value="">دون عميل</option>
                                {clients.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            <span>جهة الاتصال</span>
                            <select
                                value={project.primary_contact_id}
                                onChange={(e) =>
                                    setProject({
                                        ...project,
                                        primary_contact_id: e.target.value,
                                    })
                                }
                            >
                                <option value="">دون جهة</option>
                                {contacts.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            <span>مدير المشروع</span>
                            <select
                                value={project.manager_id}
                                onChange={(e) =>
                                    setProject({
                                        ...project,
                                        manager_id: e.target.value,
                                    })
                                }
                            >
                                <option value="">غير محدد</option>
                                {members.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            <span>الحالة</span>
                            <select
                                required
                                value={project.status_id}
                                onChange={(e) =>
                                    setProject({
                                        ...project,
                                        status_id: e.target.value,
                                    })
                                }
                            >
                                <option value="">اختر الحالة</option>
                                {statuses.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <label>
                        <span>الوصف</span>
                        <textarea
                            value={project.description}
                            onChange={(e) =>
                                setProject({
                                    ...project,
                                    description: e.target.value,
                                })
                            }
                        />
                    </label>
                    <fieldset className="existing-member-grid">
                        <legend>الفريق عند الانتقال</legend>
                        {members.map((member) => (
                            <label key={member.id}>
                                <span>{member.name}</span>
                                <select
                                    value={memberRoles[String(member.id)] ?? ''}
                                    onChange={(e) =>
                                        setMemberRoles({
                                            ...memberRoles,
                                            [String(member.id)]: e.target.value,
                                        })
                                    }
                                >
                                    <option value="">غير مضاف</option>
                                    <option value="manager">مدير</option>
                                    <option value="member">عضو</option>
                                    <option value="viewer">مشاهد</option>
                                </select>
                            </label>
                        ))}
                    </fieldset>
                </section>

                <section
                    hidden={step !== 2}
                    className="existing-project-step"
                    data-step="2"
                >
                    <div className="cloudtech-form-grid two-columns">
                        <label>
                            <span>البداية الحقيقية</span>
                            <input
                                required
                                type="date"
                                value={project.start_date}
                                onChange={(e) =>
                                    setProject({
                                        ...project,
                                        start_date: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label>
                            <span>تاريخ الانتقال للنظام</span>
                            <input
                                required
                                type="datetime-local"
                                value={transitionedAt}
                                onChange={(e) =>
                                    setTransitionedAt(e.target.value)
                                }
                            />
                        </label>
                        <label>
                            <span>النهاية المتوقعة</span>
                            <input
                                type="date"
                                value={project.end_date}
                                onChange={(e) =>
                                    setProject({
                                        ...project,
                                        end_date: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label>
                            <span>الأولوية</span>
                            <select
                                value={project.priority}
                                onChange={(e) =>
                                    setProject({
                                        ...project,
                                        priority: e.target.value,
                                    })
                                }
                            >
                                <option value="low">منخفضة</option>
                                <option value="medium">متوسطة</option>
                                <option value="high">عالية</option>
                                <option value="critical">حرجة</option>
                            </select>
                        </label>
                    </div>
                </section>

                <section
                    hidden={step !== 3}
                    className="existing-project-step existing-phase-step"
                    data-step="3"
                >
                    {phases.map((phase, index) => (
                        <fieldset key={phase.key}>
                            <legend>المرحلة {index + 1}</legend>
                            <div className="cloudtech-form-grid three-columns">
                                <label>
                                    <span>العنوان</span>
                                    <input
                                        required
                                        value={phase.title}
                                        onChange={(e) =>
                                            updatePhase(index, {
                                                title: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <label>
                                    <span>البداية</span>
                                    <input
                                        required
                                        type="datetime-local"
                                        value={phase.starts_at}
                                        onChange={(e) =>
                                            updatePhase(index, {
                                                starts_at: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <label>
                                    <span>النهاية</span>
                                    <input
                                        required
                                        type="datetime-local"
                                        value={phase.ends_at}
                                        onChange={(e) =>
                                            updatePhase(index, {
                                                ends_at: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <label>
                                    <span>الحالة</span>
                                    <select
                                        value={phase.status}
                                        onChange={(e) =>
                                            updatePhase(index, {
                                                status: e.target.value,
                                            })
                                        }
                                    >
                                        {phaseStatuses.map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label>
                                    <span>الوزن ٪</span>
                                    <input
                                        required
                                        type="number"
                                        min="0.01"
                                        max="100"
                                        step="0.01"
                                        value={phase.weight_percent}
                                        onChange={(e) =>
                                            updatePhase(index, {
                                                weight_percent: Number(
                                                    e.target.value,
                                                ),
                                            })
                                        }
                                    />
                                </label>
                                <label>
                                    <span>معايير الإكمال</span>
                                    <input
                                        value={phase.completion_criteria}
                                        onChange={(e) =>
                                            updatePhase(index, {
                                                completion_criteria:
                                                    e.target.value,
                                            })
                                        }
                                    />
                                </label>
                            </div>
                            {phases.length > 1 && (
                                <button
                                    type="button"
                                    className="is-danger"
                                    onClick={() =>
                                        setPhases(
                                            phases.filter(
                                                (_, item) => item !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 aria-hidden="true" /> إزالة
                                </button>
                            )}
                        </fieldset>
                    ))}
                    <div className="existing-inline-actions">
                        <button
                            type="button"
                            onClick={() =>
                                setPhases([
                                    ...phases,
                                    { ...newPhase(), weight_percent: 1 },
                                ])
                            }
                        >
                            <Plus aria-hidden="true" /> إضافة مرحلة
                        </button>
                        <strong
                            className={
                                weightTotal === 100 ? 'is-valid' : 'is-invalid'
                            }
                        >
                            المجموع: {weightTotal}٪
                        </strong>
                    </div>
                </section>

                <section
                    hidden={step !== 4}
                    className="existing-project-step"
                    data-step="4"
                >
                    {phases.map((phase, phaseIndex) => (
                        <fieldset key={phase.key}>
                            <legend>
                                {phase.title || `المرحلة ${phaseIndex + 1}`}
                            </legend>
                            {phase.milestones.map(
                                (milestone, milestoneIndex) => (
                                    <div
                                        className="existing-repeating-row"
                                        key={milestone.key}
                                    >
                                        <label>
                                            <span>المعلم</span>
                                            <input
                                                required
                                                value={milestone.title}
                                                onChange={(e) =>
                                                    updatePhase(phaseIndex, {
                                                        milestones:
                                                            phase.milestones.map(
                                                                (
                                                                    item,
                                                                    index,
                                                                ) =>
                                                                    index ===
                                                                    milestoneIndex
                                                                        ? {
                                                                              ...item,
                                                                              title: e
                                                                                  .target
                                                                                  .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                    })
                                                }
                                            />
                                        </label>
                                        <label>
                                            <span>التاريخ</span>
                                            <input
                                                required
                                                type="datetime-local"
                                                value={milestone.date}
                                                onChange={(e) =>
                                                    updatePhase(phaseIndex, {
                                                        milestones:
                                                            phase.milestones.map(
                                                                (
                                                                    item,
                                                                    index,
                                                                ) =>
                                                                    index ===
                                                                    milestoneIndex
                                                                        ? {
                                                                              ...item,
                                                                              date: e
                                                                                  .target
                                                                                  .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                    })
                                                }
                                            />
                                        </label>
                                        <label>
                                            <span>الحالة</span>
                                            <select
                                                value={milestone.status}
                                                onChange={(e) =>
                                                    updatePhase(phaseIndex, {
                                                        milestones:
                                                            phase.milestones.map(
                                                                (
                                                                    item,
                                                                    index,
                                                                ) =>
                                                                    index ===
                                                                    milestoneIndex
                                                                        ? {
                                                                              ...item,
                                                                              status: e
                                                                                  .target
                                                                                  .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                    })
                                                }
                                            >
                                                {phaseStatuses.map(
                                                    ([value, label]) => (
                                                        <option
                                                            key={value}
                                                            value={value}
                                                        >
                                                            {label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </label>
                                        <label className="checkbox-label">
                                            <input
                                                type="checkbox"
                                                checked={milestone.is_gate}
                                                onChange={(e) =>
                                                    updatePhase(phaseIndex, {
                                                        milestones:
                                                            phase.milestones.map(
                                                                (
                                                                    item,
                                                                    index,
                                                                ) =>
                                                                    index ===
                                                                    milestoneIndex
                                                                        ? {
                                                                              ...item,
                                                                              is_gate:
                                                                                  e
                                                                                      .target
                                                                                      .checked,
                                                                          }
                                                                        : item,
                                                            ),
                                                    })
                                                }
                                            />{' '}
                                            إلزامي
                                        </label>
                                        <button
                                            type="button"
                                            aria-label="حذف المعلم"
                                            onClick={() =>
                                                updatePhase(phaseIndex, {
                                                    milestones:
                                                        phase.milestones.filter(
                                                            (_, index) =>
                                                                index !==
                                                                milestoneIndex,
                                                        ),
                                                })
                                            }
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </button>
                                    </div>
                                ),
                            )}
                            <button
                                type="button"
                                onClick={() =>
                                    updatePhase(phaseIndex, {
                                        milestones: [
                                            ...phase.milestones,
                                            {
                                                key: crypto.randomUUID(),
                                                title: '',
                                                date: '',
                                                status: 'planned',
                                                is_gate: false,
                                            },
                                        ],
                                    })
                                }
                            >
                                <Plus aria-hidden="true" /> إضافة Milestone
                            </button>
                        </fieldset>
                    ))}
                </section>

                <section
                    hidden={step !== 5}
                    className="existing-project-step"
                    data-step="5"
                >
                    {tasks.map((task, index) => (
                        <div className="existing-repeating-row" key={task.key}>
                            <label>
                                <span>المهمة المفتوحة</span>
                                <input
                                    required
                                    value={task.title}
                                    onChange={(e) =>
                                        setTasks(
                                            tasks.map((item, i) =>
                                                i === index
                                                    ? {
                                                          ...item,
                                                          title: e.target.value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                            </label>
                            <label>
                                <span>الحالة</span>
                                <select
                                    required
                                    value={task.status_id}
                                    onChange={(e) =>
                                        setTasks(
                                            tasks.map((item, i) =>
                                                i === index
                                                    ? {
                                                          ...item,
                                                          status_id:
                                                              e.target.value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                >
                                    <option value="">اختر</option>
                                    {taskStatuses.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                <span>المرحلة</span>
                                <select
                                    value={task.phase_index}
                                    onChange={(e) =>
                                        setTasks(
                                            tasks.map((item, i) =>
                                                i === index
                                                    ? {
                                                          ...item,
                                                          phase_index: Number(
                                                              e.target.value,
                                                          ),
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                >
                                    {phases.map((phase, i) => (
                                        <option key={phase.key} value={i}>
                                            {phase.title || `المرحلة ${i + 1}`}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                <span>البداية</span>
                                <input
                                    required
                                    type="datetime-local"
                                    value={task.start_at}
                                    onChange={(e) =>
                                        setTasks(
                                            tasks.map((item, i) =>
                                                i === index
                                                    ? {
                                                          ...item,
                                                          start_at:
                                                              e.target.value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                            </label>
                            <label>
                                <span>الاستحقاق</span>
                                <input
                                    required
                                    type="datetime-local"
                                    value={task.due_at}
                                    onChange={(e) =>
                                        setTasks(
                                            tasks.map((item, i) =>
                                                i === index
                                                    ? {
                                                          ...item,
                                                          due_at: e.target
                                                              .value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                            </label>
                            <button
                                type="button"
                                aria-label="حذف المهمة"
                                onClick={() =>
                                    setTasks(
                                        tasks.filter((_, i) => i !== index),
                                    )
                                }
                            >
                                <Trash2 aria-hidden="true" />
                            </button>
                        </div>
                    ))}
                    <button
                        type="button"
                        onClick={() =>
                            setTasks([
                                ...tasks,
                                {
                                    key: crypto.randomUUID(),
                                    title: '',
                                    status_id: '',
                                    priority: 'medium',
                                    phase_index: 0,
                                    assignee_id: '',
                                    start_at: '',
                                    due_at: '',
                                },
                            ])
                        }
                    >
                        <Plus aria-hidden="true" /> إضافة مهمة مفتوحة
                    </button>
                </section>

                <section
                    hidden={step !== 6}
                    className="existing-project-step"
                    data-step="6"
                >
                    <div className="existing-split">
                        <fieldset>
                            <legend>المخاطر القائمة</legend>
                            {risks.map((risk, index) => (
                                <div
                                    className="existing-repeating-row"
                                    key={risk.key}
                                >
                                    <label>
                                        <span>المخاطرة</span>
                                        <input
                                            required
                                            value={risk.title}
                                            onChange={(e) =>
                                                setRisks(
                                                    risks.map((item, i) =>
                                                        i === index
                                                            ? {
                                                                  ...item,
                                                                  title: e
                                                                      .target
                                                                      .value,
                                                              }
                                                            : item,
                                                    ),
                                                )
                                            }
                                        />
                                    </label>
                                    <label>
                                        <span>الاحتمال</span>
                                        <input
                                            type="number"
                                            min="1"
                                            max="5"
                                            value={risk.probability}
                                            onChange={(e) =>
                                                setRisks(
                                                    risks.map((item, i) =>
                                                        i === index
                                                            ? {
                                                                  ...item,
                                                                  probability:
                                                                      Number(
                                                                          e
                                                                              .target
                                                                              .value,
                                                                      ),
                                                              }
                                                            : item,
                                                    ),
                                                )
                                            }
                                        />
                                    </label>
                                    <label>
                                        <span>الأثر</span>
                                        <input
                                            type="number"
                                            min="1"
                                            max="5"
                                            value={risk.impact}
                                            onChange={(e) =>
                                                setRisks(
                                                    risks.map((item, i) =>
                                                        i === index
                                                            ? {
                                                                  ...item,
                                                                  impact: Number(
                                                                      e.target
                                                                          .value,
                                                                  ),
                                                              }
                                                            : item,
                                                    ),
                                                )
                                            }
                                        />
                                    </label>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setRisks(
                                                risks.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 aria-hidden="true" />
                                    </button>
                                </div>
                            ))}
                            <button
                                type="button"
                                onClick={() =>
                                    setRisks([
                                        ...risks,
                                        {
                                            key: crypto.randomUUID(),
                                            title: '',
                                            probability: 2,
                                            impact: 2,
                                            status: 'open',
                                        },
                                    ])
                                }
                            >
                                <Plus aria-hidden="true" /> مخاطرة
                            </button>
                        </fieldset>
                        <fieldset>
                            <legend>المشكلات القائمة</legend>
                            {issues.map((issue, index) => (
                                <div
                                    className="existing-repeating-row"
                                    key={issue.key}
                                >
                                    <label>
                                        <span>المشكلة</span>
                                        <input
                                            required
                                            value={issue.title}
                                            onChange={(e) =>
                                                setIssues(
                                                    issues.map((item, i) =>
                                                        i === index
                                                            ? {
                                                                  ...item,
                                                                  title: e
                                                                      .target
                                                                      .value,
                                                              }
                                                            : item,
                                                    ),
                                                )
                                            }
                                        />
                                    </label>
                                    <label>
                                        <span>الشدة</span>
                                        <select
                                            value={issue.severity}
                                            onChange={(e) =>
                                                setIssues(
                                                    issues.map((item, i) =>
                                                        i === index
                                                            ? {
                                                                  ...item,
                                                                  severity:
                                                                      e.target
                                                                          .value,
                                                              }
                                                            : item,
                                                    ),
                                                )
                                            }
                                        >
                                            <option value="low">منخفضة</option>
                                            <option value="medium">
                                                متوسطة
                                            </option>
                                            <option value="high">عالية</option>
                                            <option value="critical">
                                                حرجة
                                            </option>
                                        </select>
                                    </label>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setIssues(
                                                issues.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 aria-hidden="true" />
                                    </button>
                                </div>
                            ))}
                            <button
                                type="button"
                                onClick={() =>
                                    setIssues([
                                        ...issues,
                                        {
                                            key: crypto.randomUUID(),
                                            title: '',
                                            severity: 'medium',
                                            status: 'open',
                                        },
                                    ])
                                }
                            >
                                <Plus aria-hidden="true" /> مشكلة
                            </button>
                        </fieldset>
                    </div>
                </section>

                <section
                    hidden={step !== 7}
                    className="existing-project-step existing-review"
                    data-step="7"
                >
                    <h3>راجع قبل الاعتماد</h3>
                    <dl>
                        <div>
                            <dt>المشروع</dt>
                            <dd>
                                {project.code} — {project.name}
                            </dd>
                        </div>
                        <div>
                            <dt>الانتقال</dt>
                            <dd>{transitionedAt || 'غير محدد'}</dd>
                        </div>
                        <div>
                            <dt>الخطة</dt>
                            <dd>
                                {phases.length} مراحل · {weightTotal}٪
                            </dd>
                        </div>
                        <div>
                            <dt>المعالم</dt>
                            <dd>
                                {phases.reduce(
                                    (sum, phase) =>
                                        sum + phase.milestones.length,
                                    0,
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt>المهام المفتوحة</dt>
                            <dd>{tasks.length}</dd>
                        </div>
                        <div>
                            <dt>المخاطر / المشكلات</dt>
                            <dd>
                                {risks.length} / {issues.length}
                            </dd>
                        </div>
                    </dl>
                    <p>
                        عند الاعتماد ستُحفظ Snapshot غير قابلة للتعديل وتُستخدم
                        الخطة الموزونة لحساب التقدم.
                    </p>
                </section>

                <footer className="project-wizard-actions">
                    {step > 1 && (
                        <button
                            type="button"
                            onClick={() => setStep((value) => value - 1)}
                        >
                            السابق
                        </button>
                    )}
                    {step < 7 ? (
                        <button
                            className="cloudtech-primary-action"
                            type="button"
                            onClick={next}
                        >
                            التالي
                        </button>
                    ) : (
                        <button
                            className="cloudtech-primary-action"
                            type="button"
                            disabled={busy}
                            onClick={submit}
                        >
                            {busy ? 'جارٍ الاعتماد…' : 'اعتماد وإدخال المشروع'}
                        </button>
                    )}
                </footer>
            </DialogContent>
        </Dialog>
    );
}

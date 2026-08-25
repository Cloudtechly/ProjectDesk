import { Plus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { projectApi } from './project-api';

type Milestone = {
    id?: number;
    key?: string;
    title: string;
    date: string;
    status: string;
    is_gate: boolean;
};
type Phase = {
    id?: number;
    key?: string;
    title: string;
    starts_at: string;
    ends_at: string;
    status: string;
    weight_percent: number;
    completion_criteria?: string;
    progress?: number;
    health?: string;
    awaiting_approval?: boolean;
    milestones: Milestone[];
};
type Summary = {
    progress: number;
    health: string;
    weight_total: number;
    phases: Phase[];
};

const statusOptions = [
    ['planned', 'مخطط'],
    ['in_progress', 'قيد التنفيذ'],
    ['completed', 'مكتمل'],
    ['cancelled', 'ملغى'],
];

const healthLabels: Record<string, string> = {
    on_track: 'في المسار',
    attention: 'تحتاج متابعة',
    overdue: 'متأخرة',
    completed: 'مكتملة',
};

function dateInput(value?: string) {
    return value ? value.slice(0, 16) : '';
}

export function PhasePlanWorkspace({
    projectId,
    canManage,
}: {
    projectId: number | string;
    canManage: boolean;
}) {
    const [summary, setSummary] = useState<Summary | null>(null);
    const [phases, setPhases] = useState<Phase[]>([]);
    const [editing, setEditing] = useState(false);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');

    const load = useCallback(async () => {
        try {
            const data = await projectApi<Summary>(
                `/projects/${projectId}/phase-plan`,
            );
            setSummary(data);
            setPhases(
                data.phases.map((phase) => ({
                    ...phase,
                    starts_at: dateInput(phase.starts_at),
                    ends_at: dateInput(phase.ends_at),
                    milestones: phase.milestones.map((milestone) => ({
                        ...milestone,
                        date: dateInput(
                            (milestone as Milestone & { starts_at?: string })
                                .starts_at,
                        ),
                    })),
                })),
            );
        } catch (error) {
            setMessage(
                error instanceof Error ? error.message : 'تعذر تحميل الخطة.',
            );
        }
    }, [projectId]);

    useEffect(() => {
        const timer = window.setTimeout(() => void load(), 0);

        return () => window.clearTimeout(timer);
    }, [load]);

    function updatePhase(index: number, patch: Partial<Phase>) {
        setPhases((current) =>
            current.map((phase, item) =>
                item === index ? { ...phase, ...patch } : phase,
            ),
        );
    }

    function addPhase() {
        setPhases((current) => [
            ...current,
            {
                key: crypto.randomUUID(),
                title: '',
                starts_at: '',
                ends_at: '',
                status: 'planned',
                weight_percent: current.length === 0 ? 100 : 1,
                milestones: [],
            },
        ]);
    }

    async function save() {
        setBusy(true);
        setMessage('');

        try {
            const data = await projectApi<Summary>(
                `/projects/${projectId}/phase-plan`,
                {
                    method: 'PUT',
                    body: JSON.stringify({ phases }),
                },
            );
            setSummary(data);
            setEditing(false);
            setMessage('تم حفظ الخطة والأوزان والمعالم.');
            await load();
        } catch (error) {
            setMessage(
                error instanceof Error ? error.message : 'تعذر حفظ الخطة.',
            );
        } finally {
            setBusy(false);
        }
    }

    return (
        <section
            className="phase-plan-workspace"
            aria-labelledby="phase-plan-title"
        >
            <header>
                <div>
                    <p className="cloudtech-eyebrow">خطة التسليم الموزونة</p>
                    <h2 id="phase-plan-title">المراحل والـMilestones</h2>
                    <p>
                        مجموع الأوزان يجب أن يساوي 100٪، والمعلم الإلزامي يمنع
                        إكمال مرحلته.
                    </p>
                </div>
                <div className="phase-plan-summary">
                    <strong>{summary?.progress ?? 0}٪</strong>
                    <span>
                        {summary?.health
                            ? (healthLabels[summary.health] ?? summary.health)
                            : 'غير محسوبة'}
                    </span>
                    {canManage && (
                        <button
                            type="button"
                            onClick={() => setEditing((value) => !value)}
                        >
                            {editing ? 'إلغاء التحرير' : 'تحرير الخطة'}
                        </button>
                    )}
                </div>
            </header>
            {message && (
                <p className="phase-plan-message" role="status">
                    {message}
                </p>
            )}

            {!editing ? (
                phases.length === 0 ? (
                    <p className="phase-plan-empty">
                        لا توجد خطة مراحل موزونة بعد.
                    </p>
                ) : (
                    <ol className="phase-plan-rail">
                        {phases.map((phase) => (
                            <li key={phase.id ?? phase.key}>
                                <div
                                    className="phase-plan-progress"
                                    aria-label={`تقدم ${phase.title}: ${phase.progress ?? 0}٪`}
                                >
                                    <span
                                        style={{
                                            width: `${phase.progress ?? 0}%`,
                                        }}
                                    />
                                </div>
                                <strong>{phase.title}</strong>
                                <span>
                                    {phase.weight_percent}٪ ·{' '}
                                    {phase.progress ?? 0}٪ ·{' '}
                                    {phase.health
                                        ? (healthLabels[phase.health] ??
                                          phase.health)
                                        : 'غير محسوبة'}
                                </span>
                                {phase.awaiting_approval && (
                                    <em>بانتظار اعتماد معلم إلزامي</em>
                                )}
                                {phase.milestones.length > 0 && (
                                    <ul>
                                        {phase.milestones.map((milestone) => (
                                            <li
                                                key={
                                                    milestone.id ??
                                                    milestone.key
                                                }
                                            >
                                                <span>
                                                    {milestone.is_gate
                                                        ? 'بوابة تسليم'
                                                        : 'معلم'}
                                                </span>
                                                <strong>
                                                    {milestone.title}
                                                </strong>
                                                <time>
                                                    {dateInput(
                                                        milestone.date,
                                                    ).replace('T', ' ')}
                                                </time>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ol>
                )
            ) : (
                <div className="phase-plan-editor">
                    {phases.map((phase, index) => (
                        <fieldset key={phase.id ?? phase.key}>
                            <legend>المرحلة {index + 1}</legend>
                            <div className="cloudtech-form-grid three-columns">
                                <label>
                                    <span>اسم المرحلة</span>
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
                                    <span>الحالة</span>
                                    <select
                                        value={phase.status}
                                        onChange={(e) =>
                                            updatePhase(index, {
                                                status: e.target.value,
                                            })
                                        }
                                    >
                                        {statusOptions.map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="span-two">
                                    <span>معايير الإكمال</span>
                                    <textarea
                                        value={phase.completion_criteria ?? ''}
                                        onChange={(e) =>
                                            updatePhase(index, {
                                                completion_criteria:
                                                    e.target.value,
                                            })
                                        }
                                    />
                                </label>
                            </div>
                            <div className="milestone-editor">
                                <h3>معالم المرحلة</h3>
                                {phase.milestones.map(
                                    (milestone, milestoneIndex) => (
                                        <div
                                            key={milestone.id ?? milestone.key}
                                        >
                                            <label>
                                                <span>العنوان</span>
                                                <input
                                                    required
                                                    value={milestone.title}
                                                    onChange={(e) =>
                                                        updatePhase(index, {
                                                            milestones:
                                                                phase.milestones.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
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
                                                        updatePhase(index, {
                                                            milestones:
                                                                phase.milestones.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
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
                                                        updatePhase(index, {
                                                            milestones:
                                                                phase.milestones.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
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
                                                    {statusOptions.map(
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
                                                        updatePhase(index, {
                                                            milestones:
                                                                phase.milestones.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
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
                                                معلم إلزامي
                                            </label>
                                            <button
                                                type="button"
                                                aria-label={`حذف ${milestone.title}`}
                                                onClick={() =>
                                                    updatePhase(index, {
                                                        milestones:
                                                            phase.milestones.filter(
                                                                (_, i) =>
                                                                    i !==
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
                                        updatePhase(index, {
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
                            </div>
                            <button
                                className="phase-remove"
                                type="button"
                                onClick={() =>
                                    setPhases((current) =>
                                        current.filter(
                                            (_, item) => item !== index,
                                        ),
                                    )
                                }
                            >
                                <Trash2 aria-hidden="true" /> إزالة المرحلة
                                وإعادة توزيع الوزن
                            </button>
                        </fieldset>
                    ))}
                    <footer>
                        <button type="button" onClick={addPhase}>
                            <Plus aria-hidden="true" /> إضافة مرحلة
                        </button>
                        <strong
                            className={
                                phases
                                    .filter((p) => p.status !== 'cancelled')
                                    .reduce(
                                        (sum, p) =>
                                            sum + Number(p.weight_percent),
                                        0,
                                    ) === 100
                                    ? 'is-valid'
                                    : 'is-invalid'
                            }
                        >
                            مجموع الأوزان:{' '}
                            {phases
                                .filter((p) => p.status !== 'cancelled')
                                .reduce(
                                    (sum, p) => sum + Number(p.weight_percent),
                                    0,
                                )}
                            ٪
                        </strong>
                        <button
                            className="cloudtech-primary-action"
                            type="button"
                            disabled={busy}
                            onClick={() => void save()}
                        >
                            {busy ? 'جارٍ الحفظ…' : 'حفظ الخطة'}
                        </button>
                    </footer>
                </div>
            )}
        </section>
    );
}

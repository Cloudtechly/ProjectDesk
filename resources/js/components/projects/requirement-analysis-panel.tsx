import { usePage } from '@inertiajs/react';
import {
    Bot,
    Check,
    GitMerge,
    PauseCircle,
    Pencil,
    Play,
    RefreshCw,
    ShieldAlert,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { projectApi } from './project-api';

type Version = { id: number; title?: string | null; version_number: number };
type Run = {
    id: number;
    status: string;
    model: string;
    created_at: string;
    candidates_count?: number;
    error_message?: string | null;
    injection_risk?: string;
};
type Candidate = {
    id: number;
    category_name: string;
    group_name: string;
    type: string;
    title: string;
    description?: string;
    acceptance_criteria?: string[];
    priority: string;
    source_locator_type: string;
    source_locator: string;
    source_excerpt: string;
    confidence: number;
    ambiguities?: string[];
    status: string;
    change_type: string;
    affected_entities?: { task_ids?: number[]; timeline_entry_ids?: number[] };
    relations?: Array<{ target_title: string; type: string }>;
};
type ExistingRequirement = { id: number; code: string; title: string };
type CandidateChanges = {
    category_name: string;
    group_name: string;
    type: string;
    title: string;
    description: string;
    acceptance_criteria: string;
    priority: string;
};
type Decision = {
    id: number;
    action: string;
    target_requirement_id?: number;
    changes?: Omit<CandidateChanges, 'acceptance_criteria'> & {
        acceptance_criteria: string[];
    };
};

const activeStatuses = [
    'queued',
    'waiting_for_engine',
    'extracting',
    'analyzing',
    'merging',
];
const statusLabels: Record<string, string> = {
    queued: 'في الطابور',
    waiting_for_engine: 'بانتظار المحرك المحلي',
    extracting: 'استخراج النص',
    security_review_required: 'يتطلب مراجعة أمنية',
    analyzing: 'تحليل محلي',
    merging: 'دمج وإزالة التكرار',
    review_ready: 'جاهز للمراجعة',
    approved: 'تم الاعتماد',
    failed: 'فشل',
    cancelled: 'متوقف',
};
const changeLabels: Record<string, string> = {
    new: 'جديد',
    modified: 'معدل',
    unchanged: 'دون تغيير',
    deleted: 'غائب من الإصدار الجديد',
};

export function RequirementAnalysisPanel({
    projectId,
    versions,
    canManage,
}: {
    projectId: number | string;
    versions: Version[];
    canManage: boolean;
}) {
    const page = usePage<{ auth?: { user?: { global_role?: string } } }>();
    const isAdmin = page.props.auth?.user?.global_role === 'admin';
    const [runs, setRuns] = useState<Run[]>([]);
    const [selectedVersion, setSelectedVersion] = useState('');
    const [selectedRun, setSelectedRun] = useState<number | null>(null);
    const [candidates, setCandidates] = useState<Candidate[]>([]);
    const [existingRequirements, setExistingRequirements] = useState<
        ExistingRequirement[]
    >([]);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [draft, setDraft] = useState<CandidateChanges | null>(null);
    const [mergeTargets, setMergeTargets] = useState<Record<number, string>>(
        {},
    );
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');

    const loadRuns = useCallback(async () => {
        try {
            const data = await projectApi<Run[]>(
                `/projects/${projectId}/requirement-analyses`,
            );
            setRuns(Array.isArray(data) ? data : []);
        } catch (error) {
            setMessage(
                error instanceof Error
                    ? error.message
                    : 'تعذر تحميل عمليات التحليل.',
            );
        }
    }, [projectId]);
    useEffect(() => {
        const timer = window.setTimeout(() => void loadRuns(), 0);

        return () => window.clearTimeout(timer);
    }, [loadRuns]);
    useEffect(() => {
        if (!runs.some((run) => activeStatuses.includes(run.status))) {
            return;
        }

        const timer = window.setInterval(() => void loadRuns(), 3000);

        return () => window.clearInterval(timer);
    }, [runs, loadRuns]);

    async function start() {
        if (!selectedVersion) {
            return;
        }

        setBusy(true);
        setMessage('');

        try {
            await projectApi(
                `/projects/${projectId}/requirement-book/versions/${selectedVersion}/analyses`,
                { method: 'POST', body: '{}' },
            );
            setMessage(
                'بدأ التحليل محليًا. يمكن متابعة العمل بينما يعالج الطابور الملف.',
            );
            await loadRuns();
        } catch (error) {
            setMessage(
                error instanceof Error ? error.message : 'تعذر بدء التحليل.',
            );
        } finally {
            setBusy(false);
        }
    }

    async function loadCandidates(runId: number) {
        setSelectedRun(runId);
        setBusy(true);

        try {
            const data = await projectApi<Candidate[]>(
                `/projects/${projectId}/requirement-analyses/${runId}/candidates`,
            );
            setCandidates(Array.isArray(data) ? data : []);
            const tree = await projectApi<{
                categories: Array<{
                    groups: Array<{ requirements: ExistingRequirement[] }>;
                }>;
                uncategorized: { requirements: ExistingRequirement[] };
            }>(`/projects/${projectId}/requirement-taxonomy`);
            setExistingRequirements([
                ...tree.categories.flatMap((category) =>
                    category.groups.flatMap((group) => group.requirements),
                ),
                ...tree.uncategorized.requirements,
            ]);
        } catch (error) {
            setMessage(
                error instanceof Error ? error.message : 'تعذر تحميل النتائج.',
            );
        } finally {
            setBusy(false);
        }
    }

    async function action(
        runId: number,
        suffix: 'cancel' | 'retry' | 'security-override',
    ) {
        setBusy(true);

        try {
            await projectApi(
                `/projects/${projectId}/requirement-analyses/${runId}/${suffix}`,
                { method: 'POST', body: '{}' },
            );
            await loadRuns();
        } catch (error) {
            setMessage(
                error instanceof Error ? error.message : 'تعذر تنفيذ الإجراء.',
            );
        } finally {
            setBusy(false);
        }
    }

    async function decide(decisions: Decision[]) {
        if (!selectedRun) {
            return;
        }

        setBusy(true);

        try {
            await projectApi(
                `/projects/${projectId}/requirement-analyses/${selectedRun}/decisions`,
                { method: 'POST', body: JSON.stringify({ decisions }) },
            );
            setMessage('تم حفظ قرارات المراجعة داخل معاملة واحدة.');
            setEditingId(null);
            setDraft(null);
            await loadCandidates(selectedRun);
            await loadRuns();
        } catch (error) {
            setMessage(
                error instanceof Error ? error.message : 'تعذر اعتماد النتائج.',
            );
        } finally {
            setBusy(false);
        }
    }

    function edit(candidate: Candidate) {
        setEditingId(candidate.id);
        setDraft({
            category_name: candidate.category_name,
            group_name: candidate.group_name,
            type: candidate.type,
            title: candidate.title,
            description: candidate.description ?? '',
            acceptance_criteria: (candidate.acceptance_criteria ?? []).join(
                '\n',
            ),
            priority: candidate.priority,
        });
    }

    function approveDraft(candidateId: number) {
        if (!draft) {
            return;
        }

        void decide([
            {
                id: candidateId,
                action: 'edit_approve',
                changes: {
                    ...draft,
                    acceptance_criteria: draft.acceptance_criteria
                        .split('\n')
                        .map((item) => item.trim())
                        .filter(Boolean),
                },
            },
        ]);
    }

    return (
        <section
            className="requirement-analysis"
            aria-labelledby="local-analysis-title"
        >
            <header>
                <div>
                    <Bot aria-hidden="true" />
                    <div>
                        <h2 id="local-analysis-title">
                            التحليل المحلي للمتطلبات
                        </h2>
                        <p>
                            Ollama على هذا الجهاز فقط؛ لا تُرسل الكراسة إلى
                            الإنترنت، والنتائج لا تدخل النظام قبل اعتمادك.
                        </p>
                    </div>
                </div>
            </header>
            {message && (
                <p className="analysis-message" role="status">
                    {message}
                </p>
            )}
            {canManage && versions.length > 0 && (
                <div className="analysis-start">
                    <label>
                        <span>إصدار الكراسة</span>
                        <select
                            value={selectedVersion}
                            onChange={(e) => setSelectedVersion(e.target.value)}
                        >
                            <option value="">اختر إصدارًا</option>
                            {versions.map((version) => (
                                <option key={version.id} value={version.id}>
                                    {version.title || 'كراسة المتطلبات'} — v
                                    {version.version_number}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button
                        type="button"
                        disabled={busy || !selectedVersion}
                        onClick={() => void start()}
                    >
                        <Play aria-hidden="true" /> بدء التحليل المحلي
                    </button>
                </div>
            )}
            <div className="analysis-runs">
                {runs.map((run) => (
                    <article key={run.id}>
                        <div>
                            <strong>عملية #{run.id}</strong>
                            <span>
                                {statusLabels[run.status] ?? run.status} ·{' '}
                                {run.model}
                            </span>
                            {run.error_message && (
                                <small>{run.error_message}</small>
                            )}
                        </div>
                        <div>
                            {activeStatuses.includes(run.status) && (
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() =>
                                        void action(run.id, 'cancel')
                                    }
                                >
                                    <PauseCircle aria-hidden="true" /> إيقاف
                                </button>
                            )}
                            {[
                                'failed',
                                'cancelled',
                                'waiting_for_engine',
                            ].includes(run.status) && (
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => void action(run.id, 'retry')}
                                >
                                    <RefreshCw aria-hidden="true" /> إعادة
                                </button>
                            )}
                            {run.status === 'security_review_required' &&
                                isAdmin && (
                                    <button
                                        type="button"
                                        className="is-danger"
                                        disabled={busy}
                                        onClick={() =>
                                            void action(
                                                run.id,
                                                'security-override',
                                            )
                                        }
                                    >
                                        <ShieldAlert aria-hidden="true" /> تجاوز
                                        التحذير
                                    </button>
                                )}
                            {['review_ready', 'approved'].includes(
                                run.status,
                            ) && (
                                <button
                                    type="button"
                                    onClick={() => void loadCandidates(run.id)}
                                >
                                    مراجعة النتائج ({run.candidates_count ?? 0})
                                </button>
                            )}
                        </div>
                    </article>
                ))}
            </div>
            {selectedRun && (
                <div className="candidate-review">
                    <header>
                        <h3>نتائج بانتظار قرار بشري</h3>
                        {candidates.some(
                            (candidate) =>
                                candidate.status === 'pending' &&
                                candidate.change_type !== 'deleted',
                        ) && (
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() =>
                                    void decide(
                                        candidates
                                            .filter(
                                                (candidate) =>
                                                    candidate.status ===
                                                        'pending' &&
                                                    candidate.change_type !==
                                                        'deleted',
                                            )
                                            .map((candidate) => ({
                                                id: candidate.id,
                                                action: 'approve',
                                            })),
                                    )
                                }
                            >
                                <Check aria-hidden="true" /> اعتماد النتائج
                                القابلة للإضافة
                            </button>
                        )}
                    </header>
                    {candidates.map((candidate) => (
                        <article key={candidate.id}>
                            <div className="candidate-heading">
                                <div>
                                    <span>
                                        {candidate.category_name} /{' '}
                                        {candidate.group_name}
                                    </span>
                                    <strong>{candidate.title}</strong>
                                </div>
                                <div>
                                    <em>
                                        {changeLabels[candidate.change_type] ??
                                            candidate.change_type}
                                    </em>
                                    <b>
                                        {Math.round(candidate.confidence * 100)}
                                        ٪ ثقة
                                    </b>
                                </div>
                            </div>
                            <p>{candidate.description}</p>
                            {(candidate.acceptance_criteria?.length ?? 0) >
                                0 && (
                                <details>
                                    <summary>معايير القبول</summary>
                                    <ul>
                                        {candidate.acceptance_criteria?.map(
                                            (item) => (
                                                <li key={item}>{item}</li>
                                            ),
                                        )}
                                    </ul>
                                </details>
                            )}
                            {(candidate.relations?.length ?? 0) > 0 && (
                                <details>
                                    <summary>العلاقات المقترحة</summary>
                                    <ul>
                                        {candidate.relations?.map(
                                            (relation) => (
                                                <li
                                                    key={`${relation.type}-${relation.target_title}`}
                                                >
                                                    {relation.type}:{' '}
                                                    {relation.target_title}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </details>
                            )}
                            <blockquote>
                                <small>
                                    {candidate.source_locator_type}:{' '}
                                    {candidate.source_locator}
                                </small>
                                {candidate.source_excerpt}
                            </blockquote>
                            {(candidate.ambiguities?.length ?? 0) > 0 && (
                                <details>
                                    <summary>
                                        أسئلة وغموض (
                                        {candidate.ambiguities?.length})
                                    </summary>
                                    <ul>
                                        {candidate.ambiguities?.map((item) => (
                                            <li key={item}>{item}</li>
                                        ))}
                                    </ul>
                                </details>
                            )}
                            {((candidate.affected_entities?.task_ids?.length ??
                                0) > 0 ||
                                (candidate.affected_entities?.timeline_entry_ids
                                    ?.length ?? 0) > 0) && (
                                <p className="candidate-impact">
                                    قد يتأثر:{' '}
                                    {candidate.affected_entities?.task_ids
                                        ?.length ?? 0}{' '}
                                    مهمة ·{' '}
                                    {candidate.affected_entities
                                        ?.timeline_entry_ids?.length ?? 0}{' '}
                                    مرحلة أو معلم
                                </p>
                            )}
                            {editingId === candidate.id && draft && (
                                <fieldset className="candidate-edit-form">
                                    <legend>
                                        تعديل أو نقل النتيجة قبل الاعتماد
                                    </legend>
                                    <label>
                                        <span>الفئة</span>
                                        <input
                                            value={draft.category_name}
                                            onChange={(event) =>
                                                setDraft({
                                                    ...draft,
                                                    category_name:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                    <label>
                                        <span>المجموعة</span>
                                        <input
                                            value={draft.group_name}
                                            onChange={(event) =>
                                                setDraft({
                                                    ...draft,
                                                    group_name:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                    <label>
                                        <span>نوع المتطلب</span>
                                        <select
                                            value={draft.type}
                                            onChange={(event) =>
                                                setDraft({
                                                    ...draft,
                                                    type: event.target.value,
                                                })
                                            }
                                        >
                                            <option value="functional">
                                                وظيفي
                                            </option>
                                            <option value="technical">
                                                تقني
                                            </option>
                                            <option value="non_functional">
                                                غير وظيفي
                                            </option>
                                            <option value="security">
                                                أمني
                                            </option>
                                            <option value="data">بيانات</option>
                                            <option value="integration">
                                                تكامل
                                            </option>
                                            <option value="business">
                                                أعمال
                                            </option>
                                        </select>
                                    </label>
                                    <label>
                                        <span>الأولوية</span>
                                        <select
                                            value={draft.priority}
                                            onChange={(event) =>
                                                setDraft({
                                                    ...draft,
                                                    priority:
                                                        event.target.value,
                                                })
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
                                    <label className="span-two">
                                        <span>العنوان</span>
                                        <input
                                            value={draft.title}
                                            onChange={(event) =>
                                                setDraft({
                                                    ...draft,
                                                    title: event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                    <label className="span-two">
                                        <span>الوصف</span>
                                        <textarea
                                            value={draft.description}
                                            onChange={(event) =>
                                                setDraft({
                                                    ...draft,
                                                    description:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                    <label className="span-two">
                                        <span>
                                            معايير القبول — معيار في كل سطر
                                        </span>
                                        <textarea
                                            value={draft.acceptance_criteria}
                                            onChange={(event) =>
                                                setDraft({
                                                    ...draft,
                                                    acceptance_criteria:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                    <div className="span-two">
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() =>
                                                approveDraft(candidate.id)
                                            }
                                        >
                                            <Check aria-hidden="true" /> حفظ
                                            واعتماد
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setEditingId(null);
                                                setDraft(null);
                                            }}
                                        >
                                            إلغاء التعديل
                                        </button>
                                    </div>
                                </fieldset>
                            )}
                            {candidate.status === 'pending' && canManage && (
                                <footer>
                                    {candidate.change_type !== 'deleted' ? (
                                        <>
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() =>
                                                    void decide([
                                                        {
                                                            id: candidate.id,
                                                            action: 'approve',
                                                        },
                                                    ])
                                                }
                                            >
                                                <Check aria-hidden="true" />{' '}
                                                اعتماد
                                            </button>
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() => edit(candidate)}
                                            >
                                                <Pencil aria-hidden="true" />{' '}
                                                تعديل أو نقل واعتماد
                                            </button>
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() =>
                                                    void decide(
                                                        candidates
                                                            .filter(
                                                                (item) =>
                                                                    item.status ===
                                                                        'pending' &&
                                                                    item.change_type !==
                                                                        'deleted' &&
                                                                    item.category_name ===
                                                                        candidate.category_name &&
                                                                    item.group_name ===
                                                                        candidate.group_name,
                                                            )
                                                            .map((item) => ({
                                                                id: item.id,
                                                                action: 'approve',
                                                            })),
                                                    )
                                                }
                                            >
                                                اعتماد المجموعة كاملة
                                            </button>
                                        </>
                                    ) : (
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() =>
                                                void decide([
                                                    {
                                                        id: candidate.id,
                                                        action: 'approve',
                                                    },
                                                ])
                                            }
                                        >
                                            تأكيد مراجعة الغياب
                                        </button>
                                    )}
                                    {candidate.change_type !== 'deleted' && (
                                        <>
                                            <label className="candidate-merge">
                                                <span className="sr-only">
                                                    المتطلب المراد الدمج معه
                                                </span>
                                                <select
                                                    value={
                                                        mergeTargets[
                                                            candidate.id
                                                        ] ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        setMergeTargets({
                                                            ...mergeTargets,
                                                            [candidate.id]:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                >
                                                    <option value="">
                                                        اختر متطلبًا للدمج
                                                    </option>
                                                    {existingRequirements.map(
                                                        (requirement) => (
                                                            <option
                                                                key={
                                                                    requirement.id
                                                                }
                                                                value={
                                                                    requirement.id
                                                                }
                                                            >
                                                                {
                                                                    requirement.code
                                                                }{' '}
                                                                —{' '}
                                                                {
                                                                    requirement.title
                                                                }
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </label>
                                            <button
                                                type="button"
                                                disabled={
                                                    busy ||
                                                    !mergeTargets[candidate.id]
                                                }
                                                onClick={() =>
                                                    void decide([
                                                        {
                                                            id: candidate.id,
                                                            action: 'merge',
                                                            target_requirement_id:
                                                                Number(
                                                                    mergeTargets[
                                                                        candidate
                                                                            .id
                                                                    ],
                                                                ),
                                                        },
                                                    ])
                                                }
                                            >
                                                <GitMerge aria-hidden="true" />{' '}
                                                دمج
                                            </button>
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() =>
                                                    void decide([
                                                        {
                                                            id: candidate.id,
                                                            action: 'question',
                                                        },
                                                    ])
                                                }
                                            >
                                                تحويل إلى سؤال
                                            </button>
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() =>
                                                    void decide([
                                                        {
                                                            id: candidate.id,
                                                            action: 'risk',
                                                        },
                                                    ])
                                                }
                                            >
                                                تحويل إلى مخاطرة
                                            </button>
                                        </>
                                    )}
                                    <button
                                        type="button"
                                        className="is-danger"
                                        disabled={busy}
                                        onClick={() =>
                                            void decide([
                                                {
                                                    id: candidate.id,
                                                    action: 'reject',
                                                },
                                            ])
                                        }
                                    >
                                        <X aria-hidden="true" /> رفض
                                    </button>
                                </footer>
                            )}
                        </article>
                    ))}
                </div>
            )}
        </section>
    );
}

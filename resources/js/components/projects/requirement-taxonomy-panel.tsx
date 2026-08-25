import {
    ChevronDown,
    ChevronUp,
    FolderTree,
    GitMerge,
    Link2,
    Plus,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useCallback, useEffect, useState } from 'react';
import { projectApi } from './project-api';

type Requirement = {
    id: number;
    code: string;
    title: string;
    type: string;
    priority: string;
    tasks_count: number;
    outgoing_relations?: Array<{
        id: number;
        type: string;
        target: { id: number; code: string; title: string };
    }>;
    status?: { label: string; semantic: string; color: string };
};
type Group = {
    id: number;
    name: string;
    position?: number;
    requirements: Requirement[];
};
type Category = {
    id: number;
    name: string;
    position?: number;
    groups: Group[];
};
type Tree = {
    categories: Category[];
    uncategorized: { name: string; requirements: Requirement[] };
};

const typeLabels: Record<string, string> = {
    functional: 'وظيفي',
    technical: 'تقني',
    non_functional: 'غير وظيفي',
    security: 'أمني',
    data: 'بيانات',
    integration: 'تكامل',
    business: 'أعمال',
};

export function RequirementTaxonomyPanel({
    projectId,
    canManage,
}: {
    projectId: number | string;
    canManage: boolean;
}) {
    const [tree, setTree] = useState<Tree | null>(null);
    const [categoryName, setCategoryName] = useState('');
    const [groupNames, setGroupNames] = useState<Record<number, string>>({});
    const [mergeTargets, setMergeTargets] = useState<Record<number, string>>(
        {},
    );
    const [relationTargets, setRelationTargets] = useState<
        Record<number, string>
    >({});
    const [relationTypes, setRelationTypes] = useState<Record<number, string>>(
        {},
    );
    const [message, setMessage] = useState('');

    const load = useCallback(async () => {
        try {
            setTree(
                await projectApi<Tree>(
                    `/projects/${projectId}/requirement-taxonomy`,
                ),
            );
        } catch (error) {
            setMessage(
                error instanceof Error
                    ? error.message
                    : 'تعذر تحميل شجرة المتطلبات.',
            );
        }
    }, [projectId]);
    useEffect(() => {
        const timer = window.setTimeout(() => void load(), 0);

        return () => window.clearTimeout(timer);
    }, [load]);

    async function addCategory(event: FormEvent) {
        event.preventDefault();

        if (!categoryName.trim()) {
            return;
        }

        try {
            await projectApi(`/projects/${projectId}/requirement-categories`, {
                method: 'POST',
                body: JSON.stringify({ name: categoryName }),
            });
            setCategoryName('');
            await load();
        } catch (error) {
            setMessage(
                error instanceof Error ? error.message : 'تعذر إضافة الفئة.',
            );
        }
    }

    async function addGroup(categoryId: number) {
        const name = groupNames[categoryId]?.trim();

        if (!name) {
            return;
        }

        try {
            await projectApi(
                `/projects/${projectId}/requirement-categories/${categoryId}/groups`,
                { method: 'POST', body: JSON.stringify({ name }) },
            );
            setGroupNames((current) => ({ ...current, [categoryId]: '' }));
            await load();
        } catch (error) {
            setMessage(
                error instanceof Error ? error.message : 'تعذر إضافة المجموعة.',
            );
        }
    }

    async function updateGroup(groupId: number, data: Record<string, unknown>) {
        try {
            await projectApi(
                `/projects/${projectId}/requirement-groups/${groupId}`,
                { method: 'PUT', body: JSON.stringify(data) },
            );
            await load();
        } catch (error) {
            setMessage(
                error instanceof Error ? error.message : 'تعذر تحديث المجموعة.',
            );
        }
    }

    async function reorderCategories(
        category: Category,
        adjacent: Category,
        categoryIndex: number,
        adjacentIndex: number,
    ) {
        try {
            await projectApi(
                `/projects/${projectId}/requirement-categories/${category.id}`,
                {
                    method: 'PUT',
                    body: JSON.stringify({ position: adjacentIndex }),
                },
            );
            await projectApi(
                `/projects/${projectId}/requirement-categories/${adjacent.id}`,
                {
                    method: 'PUT',
                    body: JSON.stringify({ position: categoryIndex }),
                },
            );
            await load();
        } catch (error) {
            setMessage(
                error instanceof Error ? error.message : 'تعذر ترتيب الفئات.',
            );
        }
    }

    async function reorderGroups(
        group: Group,
        adjacent: Group,
        groupIndex: number,
        adjacentIndex: number,
    ) {
        try {
            await projectApi(
                `/projects/${projectId}/requirement-groups/${group.id}`,
                {
                    method: 'PUT',
                    body: JSON.stringify({ position: adjacentIndex }),
                },
            );
            await projectApi(
                `/projects/${projectId}/requirement-groups/${adjacent.id}`,
                {
                    method: 'PUT',
                    body: JSON.stringify({ position: groupIndex }),
                },
            );
            await load();
        } catch (error) {
            setMessage(
                error instanceof Error
                    ? error.message
                    : 'تعذر ترتيب المجموعات.',
            );
        }
    }

    async function mergeGroup(groupId: number) {
        const target = mergeTargets[groupId];

        if (!target) {
            return;
        }

        await projectApi(
            `/projects/${projectId}/requirement-groups/${groupId}/merge`,
            {
                method: 'POST',
                body: JSON.stringify({ target_group_id: Number(target) }),
            },
        );
        setMergeTargets((current) => ({ ...current, [groupId]: '' }));
        await load();
    }

    async function addRelation(requirementId: number) {
        const target = relationTargets[requirementId];

        if (!target) {
            return;
        }

        await projectApi(
            `/projects/${projectId}/requirements/${requirementId}/relations`,
            {
                method: 'POST',
                body: JSON.stringify({
                    target_requirement_id: Number(target),
                    type: relationTypes[requirementId] ?? 'related_to',
                }),
            },
        );
        await load();
    }

    async function deleteRelation(relationId: number) {
        await projectApi(
            `/projects/${projectId}/requirement-relations/${relationId}`,
            { method: 'DELETE' },
        );
        await load();
    }

    function requirementRow(requirement: Requirement) {
        const allRequirements = [
            ...(tree?.categories.flatMap((category) =>
                category.groups.flatMap((group) => group.requirements),
            ) ?? []),
            ...(tree?.uncategorized.requirements ?? []),
        ];

        return (
            <li key={requirement.id}>
                <span className="dashboard-code" dir="ltr">
                    {requirement.code}
                </span>
                <div>
                    <strong>{requirement.title}</strong>
                    <small>
                        {typeLabels[requirement.type] ?? requirement.type} ·
                        مرتبط بـ {requirement.tasks_count} مهمة
                    </small>
                </div>
                <span
                    className="requirement-coverage"
                    data-covered={requirement.tasks_count > 0}
                >
                    {requirement.tasks_count > 0 ? 'مغطى' : 'غير منفذ'}
                </span>
                {(requirement.outgoing_relations?.length ?? 0) > 0 && (
                    <div className="requirement-relation-tags">
                        {requirement.outgoing_relations?.map((relation) => (
                            <span key={relation.id}>
                                {relation.type}: {relation.target.code}
                                <button
                                    type="button"
                                    aria-label={`حذف علاقة ${relation.target.title}`}
                                    onClick={() =>
                                        void deleteRelation(relation.id)
                                    }
                                >
                                    ×
                                </button>
                            </span>
                        ))}
                    </div>
                )}
                {canManage && (
                    <div className="requirement-relation-add">
                        <select
                            aria-label="المتطلب الهدف"
                            value={relationTargets[requirement.id] ?? ''}
                            onChange={(event) =>
                                setRelationTargets({
                                    ...relationTargets,
                                    [requirement.id]: event.target.value,
                                })
                            }
                        >
                            <option value="">اختر متطلبًا للربط</option>
                            {allRequirements
                                .filter((item) => item.id !== requirement.id)
                                .map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.code} — {item.title}
                                    </option>
                                ))}
                        </select>
                        <select
                            aria-label="نوع العلاقة"
                            value={
                                relationTypes[requirement.id] ?? 'related_to'
                            }
                            onChange={(event) =>
                                setRelationTypes({
                                    ...relationTypes,
                                    [requirement.id]: event.target.value,
                                })
                            }
                        >
                            <option value="depends_on">يعتمد على</option>
                            <option value="complements">يكمّل</option>
                            <option value="details">يفصّل</option>
                            <option value="conflicts_with">يتعارض مع</option>
                            <option value="duplicates">مكرر</option>
                            <option value="replaces">يستبدل</option>
                            <option value="related_to">مرتبط بـ</option>
                        </select>
                        <button
                            type="button"
                            disabled={!relationTargets[requirement.id]}
                            onClick={() => void addRelation(requirement.id)}
                        >
                            <Link2 aria-hidden="true" /> ربط
                        </button>
                    </div>
                )}
            </li>
        );
    }

    return (
        <section
            className="requirement-taxonomy"
            aria-labelledby="taxonomy-title"
        >
            <header>
                <div>
                    <FolderTree aria-hidden="true" />
                    <div>
                        <h3 id="taxonomy-title">شجرة المتطلبات</h3>
                        <p>
                            فئات، ثم مجموعات مترابطة، ثم متطلبات قابلة للتتبع.
                        </p>
                    </div>
                </div>
                {canManage && (
                    <form onSubmit={(event) => void addCategory(event)}>
                        <label className="sr-only" htmlFor="new-category">
                            اسم الفئة الجديدة
                        </label>
                        <input
                            id="new-category"
                            value={categoryName}
                            onChange={(event) =>
                                setCategoryName(event.target.value)
                            }
                            placeholder="فئة جديدة"
                        />
                        <button type="submit">
                            <Plus aria-hidden="true" /> إضافة فئة
                        </button>
                    </form>
                )}
            </header>
            {message && <p role="status">{message}</p>}
            <div className="requirement-tree">
                {tree?.categories.map((category, categoryIndex) => (
                    <details key={category.id} open>
                        <summary>
                            <strong>{category.name}</strong>
                            <span>
                                {category.groups.reduce(
                                    (sum, group) =>
                                        sum + group.requirements.length,
                                    0,
                                )}{' '}
                                متطلب
                            </span>
                            {canManage && (
                                <span className="taxonomy-order-actions">
                                    <button
                                        type="button"
                                        disabled={categoryIndex === 0}
                                        aria-label={`نقل ${category.name} للأعلى`}
                                        onClick={(event) => {
                                            event.preventDefault();
                                            const previous =
                                                tree.categories[
                                                    categoryIndex - 1
                                                ];
                                            void reorderCategories(
                                                category,
                                                previous,
                                                categoryIndex,
                                                categoryIndex - 1,
                                            );
                                        }}
                                    >
                                        <ChevronUp aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        disabled={
                                            categoryIndex ===
                                            tree.categories.length - 1
                                        }
                                        aria-label={`نقل ${category.name} للأسفل`}
                                        onClick={(event) => {
                                            event.preventDefault();
                                            const next =
                                                tree.categories[
                                                    categoryIndex + 1
                                                ];
                                            void reorderCategories(
                                                category,
                                                next,
                                                categoryIndex,
                                                categoryIndex + 1,
                                            );
                                        }}
                                    >
                                        <ChevronDown aria-hidden="true" />
                                    </button>
                                </span>
                            )}
                        </summary>
                        <div>
                            {category.groups.map((group, groupIndex) => (
                                <details key={group.id} open>
                                    <summary>
                                        <strong>{group.name}</strong>
                                        <span>{group.requirements.length}</span>
                                    </summary>
                                    {canManage && (
                                        <div className="taxonomy-group-tools">
                                            <button
                                                type="button"
                                                disabled={groupIndex === 0}
                                                onClick={() =>
                                                    void reorderGroups(
                                                        group,
                                                        category.groups[
                                                            groupIndex - 1
                                                        ],
                                                        groupIndex,
                                                        groupIndex - 1,
                                                    )
                                                }
                                            >
                                                <ChevronUp aria-hidden="true" />{' '}
                                                للأعلى
                                            </button>
                                            <button
                                                type="button"
                                                disabled={
                                                    groupIndex ===
                                                    category.groups.length - 1
                                                }
                                                onClick={() =>
                                                    void reorderGroups(
                                                        group,
                                                        category.groups[
                                                            groupIndex + 1
                                                        ],
                                                        groupIndex,
                                                        groupIndex + 1,
                                                    )
                                                }
                                            >
                                                <ChevronDown aria-hidden="true" />{' '}
                                                للأسفل
                                            </button>
                                            <label>
                                                <span>نقل إلى فئة</span>
                                                <select
                                                    value={category.id}
                                                    onChange={(event) =>
                                                        void updateGroup(
                                                            group.id,
                                                            {
                                                                category_id:
                                                                    Number(
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    ),
                                                            },
                                                        )
                                                    }
                                                >
                                                    {tree.categories.map(
                                                        (item) => (
                                                            <option
                                                                key={item.id}
                                                                value={item.id}
                                                            >
                                                                {item.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </label>
                                            <label>
                                                <span>دمج مع</span>
                                                <select
                                                    value={
                                                        mergeTargets[
                                                            group.id
                                                        ] ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        setMergeTargets({
                                                            ...mergeTargets,
                                                            [group.id]:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                >
                                                    <option value="">
                                                        اختر مجموعة
                                                    </option>
                                                    {tree.categories
                                                        .flatMap(
                                                            (item) =>
                                                                item.groups,
                                                        )
                                                        .filter(
                                                            (item) =>
                                                                item.id !==
                                                                group.id,
                                                        )
                                                        .map((item) => (
                                                            <option
                                                                key={item.id}
                                                                value={item.id}
                                                            >
                                                                {item.name}
                                                            </option>
                                                        ))}
                                                </select>
                                            </label>
                                            <button
                                                type="button"
                                                disabled={
                                                    !mergeTargets[group.id]
                                                }
                                                onClick={() =>
                                                    void mergeGroup(group.id)
                                                }
                                            >
                                                <GitMerge aria-hidden="true" />{' '}
                                                دمج
                                            </button>
                                        </div>
                                    )}
                                    {group.requirements.length > 0 ? (
                                        <ul>
                                            {group.requirements.map(
                                                requirementRow,
                                            )}
                                        </ul>
                                    ) : (
                                        <p>لا توجد متطلبات في هذه المجموعة.</p>
                                    )}
                                </details>
                            ))}
                            {canManage && (
                                <div className="requirement-group-add">
                                    <label
                                        className="sr-only"
                                        htmlFor={`group-${category.id}`}
                                    >
                                        اسم المجموعة داخل {category.name}
                                    </label>
                                    <input
                                        id={`group-${category.id}`}
                                        value={groupNames[category.id] ?? ''}
                                        onChange={(event) =>
                                            setGroupNames((current) => ({
                                                ...current,
                                                [category.id]:
                                                    event.target.value,
                                            }))
                                        }
                                        placeholder="مجموعة جديدة"
                                    />
                                    <button
                                        type="button"
                                        onClick={() =>
                                            void addGroup(category.id)
                                        }
                                    >
                                        <Plus aria-hidden="true" /> إضافة مجموعة
                                    </button>
                                </div>
                            )}
                        </div>
                    </details>
                ))}
                {(tree?.uncategorized.requirements.length ?? 0) > 0 && (
                    <details open className="uncategorized">
                        <summary>
                            <strong>غير مصنف</strong>
                            <span>
                                {tree?.uncategorized.requirements.length}
                            </span>
                        </summary>
                        <ul>
                            {tree?.uncategorized.requirements.map(
                                requirementRow,
                            )}
                        </ul>
                    </details>
                )}
            </div>
        </section>
    );
}

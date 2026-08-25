import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { fallbackSettings } from '@/components/settings/settings-contracts';
import type {
    EditableGroup,
    LocalEngineStatus,
    Section,
    SettingsData,
    WorkflowEntity,
    WorkflowStatus,
} from '@/components/settings/settings-contracts';
import { SettingsWorkspace } from '@/components/settings/settings-workspace';

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
    const [engineStatus, setEngineStatus] = useState<LocalEngineStatus | null>(
        null,
    );
    const [engineLoading, setEngineLoading] = useState(false);

    const testLocalEngine = useCallback(async () => {
        setEngineLoading(true);
        setError('');

        try {
            setEngineStatus(
                await jsonRequest('/system-settings/local-ai/status'),
            );
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر اختبار المحرك المحلي.',
            );
        } finally {
            setEngineLoading(false);
        }
    }, []);

    useEffect(() => {
        if (activeSection === 'local_ai' && engineStatus === null) {
            const timer = window.setTimeout(() => void testLocalEngine(), 0);

            return () => window.clearTimeout(timer);
        }
    }, [activeSection, engineStatus, testLocalEngine]);

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

    return (
        <SettingsWorkspace
            activeSection={activeSection}
            setActiveSection={setActiveSection}
            settings={settings}
            loading={loading}
            saving={saving}
            notice={notice}
            error={error}
            patchGroup={patchGroup}
            saveGroup={saveGroup}
            resetGroup={resetGroup}
            loadSettings={loadSettings}
            workflowEntity={workflowEntity}
            setWorkflowEntity={setWorkflowEntity}
            workflowStatuses={workflowStatuses}
            workflowLoading={workflowLoading}
            workflowSaving={workflowSaving}
            loadWorkflow={loadWorkflow}
            updateWorkflowStatus={updateWorkflowStatus}
            moveWorkflowStatus={moveWorkflowStatus}
            saveWorkflow={saveWorkflow}
            engineStatus={engineStatus}
            engineLoading={engineLoading}
            testLocalEngine={testLocalEngine}
        />
    );
}

SettingsIndex.layout = {
    breadcrumbs: [{ title: 'الإعدادات', href: '/settings' }],
};

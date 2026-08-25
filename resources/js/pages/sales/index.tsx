import { router, useForm } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import type {
    InvoiceClientPreview,
    InvoiceCompanyPreview,
} from '@/components/sales/invoice-template-preview';
import {
    collection,
    initialInvoiceForm,
    initialLine,
} from '@/components/sales/sales-contracts';
import type {
    LineItem,
    SalesDocument,
    SalesProps,
} from '@/components/sales/sales-contracts';
import { SalesWorkspace } from '@/components/sales/sales-workspace';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { calculateInvoiceTotals } from '@/lib/invoice-calculator';

export default function SalesIndex({
    documents,
    filters,
    projects = [],
    clients = [],
    formProjects = [],
    formClients = [],
    company = {},
    canCreate = false,
}: SalesProps) {
    const rows = collection(documents);
    const paginator = Array.isArray(documents) ? undefined : documents;
    const [builderOpen, setBuilderOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [currentPermissions, setCurrentPermissions] = useState<
        SalesDocument['permissions']
    >({ update: true });
    const [loadingId, setLoadingId] = useState<number | null>(null);
    const [previewNumber, setPreviewNumber] = useState(
        `CT-INV-${new Date().getFullYear()}-…`,
    );
    const [loadedClientId, setLoadedClientId] = useState<number | null>(null);
    const [loadedClientSnapshot, setLoadedClientSnapshot] =
        useState<InvoiceClientPreview | null>(null);
    const [loadedCompanySnapshot, setLoadedCompanySnapshot] =
        useState<InvoiceCompanyPreview | null>(null);
    const [mobilePane, setMobilePane] = useState<'fields' | 'preview'>(
        'fields',
    );
    const form = useForm(initialInvoiceForm());
    const returnFocusRef = useRef<HTMLElement | null>(null);
    const { allowNextNavigation, confirmDiscard } = useUnsavedChanges(
        builderOpen && form.isDirty,
        'لديك تغييرات غير محفوظة في قالب الفاتورة. هل تريد تجاهلها؟',
    );
    const canEditCurrent =
        editingId === null || currentPermissions?.update === true;
    const selectableProjects = canEditCurrent ? formProjects : projects;
    const selectableClients = canEditCurrent ? formClients : clients;
    const visibleProjects = selectableProjects.filter(
        (project) =>
            !form.data.client_id ||
            !project.client_id ||
            String(project.client_id) === String(form.data.client_id),
    );
    const totals = useMemo(
        () =>
            calculateInvoiceTotals(
                form.data.line_items,
                form.data.discount_rate,
                form.data.tax_rate,
            ),
        [form.data.line_items, form.data.discount_rate, form.data.tax_rate],
    );
    const selectedClient = selectableClients.find(
        (client) => String(client.id) === form.data.client_id,
    );
    const previewClient =
        loadedClientId !== null &&
        String(loadedClientId) === form.data.client_id &&
        loadedClientSnapshot
            ? loadedClientSnapshot
            : selectedClient;
    const previewCompany = loadedCompanySnapshot || company;

    function openNew(opener: HTMLElement) {
        returnFocusRef.current = opener;
        const nextData = initialInvoiceForm();
        form.setDefaults(nextData);
        form.setData(nextData);
        form.clearErrors();
        setEditingId(null);
        setPreviewNumber(`CT-INV-${new Date().getFullYear()}-…`);
        setLoadedClientId(null);
        setLoadedClientSnapshot(null);
        setLoadedCompanySnapshot(null);
        setCurrentPermissions({ update: true });
        setMobilePane('fields');
        setBuilderOpen(true);
    }

    async function openExisting(id: number, opener: HTMLElement) {
        returnFocusRef.current = opener;
        setLoadingId(id);

        try {
            const response = await fetch(`/sales/${id}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error('تعذر تحميل قالب الفاتورة.');
            }

            const payload = (await response.json()) as {
                document: SalesDocument;
            };
            const document = payload.document;
            const nextData = {
                ...initialInvoiceForm(),
                title: document.title,
                client_id: document.clientId ? String(document.clientId) : '',
                project_id: document.projectId
                    ? String(document.projectId)
                    : '',
                issue_date: document.issueDate || '',
                due_date: document.dueDate || '',
                reference: document.reference || '',
                currency: document.currency,
                discount_rate: String(document.discountRate),
                tax_rate: String(document.taxRate),
                notes: document.notes || '',
                lock_version: String(document.lockVersion),
                line_items:
                    document.lineItems.length > 0
                        ? document.lineItems.map((item) => ({
                              name: item.name,
                              description: item.description || '',
                              quantity: String(item.quantity),
                              unit: item.unit,
                              unit_price: String(item.unitPrice),
                          }))
                        : [initialLine()],
            };
            form.setDefaults(nextData);
            form.setData(nextData);
            form.clearErrors();
            setEditingId(id);
            setPreviewNumber(document.number);
            setLoadedClientId(document.clientId ?? null);
            setLoadedClientSnapshot(document.clientSnapshot ?? null);
            setLoadedCompanySnapshot(document.companySnapshot ?? null);
            setCurrentPermissions(document.permissions ?? {});
            setMobilePane('fields');
            setBuilderOpen(true);
        } catch (error) {
            window.alert(
                error instanceof Error ? error.message : 'تعذر تحميل القالب.',
            );
        } finally {
            setLoadingId(null);
        }
    }

    function updateLine(index: number, key: keyof LineItem, value: string) {
        form.setData(
            'line_items',
            form.data.line_items.map((item, itemIndex) =>
                itemIndex === index ? { ...item, [key]: value } : item,
            ),
        );
    }

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!canEditCurrent) {
            return;
        }

        allowNextNavigation();
        form.transform((data) => ({
            type: 'invoice',
            status: 'draft',
            title: data.title,
            client_id: data.client_id || null,
            project_id: data.project_id || null,
            issue_date: data.issue_date || null,
            due_date: data.due_date || null,
            reference: data.reference || null,
            currency: data.currency,
            discount_rate: data.discount_rate,
            tax_rate: data.tax_rate,
            notes: data.notes || null,
            line_items: data.line_items,
            ...(editingId ? { lock_version: data.lock_version } : {}),
        }));
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults();
                setBuilderOpen(false);
                form.reset();
            },
        };

        if (editingId) {
            form.put(`/sales/${editingId}`, options);
        } else {
            form.post('/sales', options);
        }
    }

    function duplicateTemplate() {
        if (
            !editingId ||
            !confirmDiscard() ||
            !window.confirm('إنشاء نسخة مستقلة من قالب الفاتورة؟')
        ) {
            return;
        }

        allowNextNavigation();
        router.post(
            `/sales/${editingId}/duplicate`,
            { lock_version: form.data.lock_version },
            { preserveScroll: true, onSuccess: () => setBuilderOpen(false) },
        );
    }

    function changeArchiveState(action: 'archive' | 'restore') {
        if (!editingId || !confirmDiscard()) {
            return;
        }

        const verb = action === 'archive' ? 'أرشفة' : 'استعادة';

        if (!window.confirm(`${verb} قالب الفاتورة؟`)) {
            return;
        }

        allowNextNavigation();
        router.post(
            `/sales/${editingId}/${action}`,
            { lock_version: form.data.lock_version },
            { preserveScroll: true, onSuccess: () => setBuilderOpen(false) },
        );
    }

    function closeBuilder() {
        if (!confirmDiscard()) {
            return;
        }

        form.reset();
        form.clearErrors();
        setBuilderOpen(false);
    }

    return (
        <SalesWorkspace
            filters={filters}
            projects={projects}
            canCreate={canCreate}
            rows={rows}
            paginator={paginator}
            builderOpen={builderOpen}
            editingId={editingId}
            currentPermissions={currentPermissions}
            loadingId={loadingId}
            previewNumber={previewNumber}
            mobilePane={mobilePane}
            setMobilePane={setMobilePane}
            returnFocusRef={returnFocusRef}
            canEditCurrent={canEditCurrent}
            selectableClients={selectableClients}
            visibleProjects={visibleProjects}
            totals={totals}
            previewClient={previewClient}
            previewCompany={previewCompany}
            form={form}
            openNew={openNew}
            openExisting={openExisting}
            updateLine={updateLine}
            submit={submit}
            duplicateTemplate={duplicateTemplate}
            changeArchiveState={changeArchiveState}
            closeBuilder={closeBuilder}
            confirmDiscard={confirmDiscard}
        />
    );
}

SalesIndex.layout = {
    breadcrumbs: [{ title: 'قوالب الفواتير', href: '/sales' }],
};

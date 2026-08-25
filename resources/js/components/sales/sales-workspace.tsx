import { Head, Link } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import {
    Archive,
    ArrowLeft,
    Check,
    Copy,
    FileDown,
    FileText,
    LoaderCircle,
    Plus,
    RotateCcw,
    Search,
    Trash2,
    X,
} from 'lucide-react';
import type { Dispatch, FormEvent, RefObject, SetStateAction } from 'react';
import InputError from '@/components/input-error';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    createLocaleDateTimeFormatter,
    createLocaleNumberFormatter,
} from '@/i18n/formatters';
import type { calculateInvoiceTotals } from '@/lib/invoice-calculator';
import type {
    InvoiceClientPreview,
    InvoiceCompanyPreview,
} from './invoice-template-preview';
import InvoiceTemplatePreview from './invoice-template-preview';
import type {
    InvoiceFormData,
    LineItem,
    Option,
    Paginator,
    SalesDocument,
    SalesProps,
    SalesSummary,
} from './sales-contracts';
import { initialLine } from './sales-contracts';

const unitOptions = [
    'مشروع',
    'مرحلة عمل',
    'شاشة',
    'صفحة',
    'ساعة',
    'يوم',
    'أسبوع',
    'شهر',
    'ترخيص',
    'تكامل',
    'جلسة',
];

const moneyFormatter = createLocaleNumberFormatter({
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

const dateFormatter = createLocaleDateTimeFormatter({
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

function formatDate(value?: string | null) {
    if (!value) {
        return 'غير محدد';
    }

    const date = new Date(`${value}T12:00:00`);

    return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

type SalesWorkspaceProps = {
    filters?: SalesProps['filters'];
    projects: Option[];
    canCreate: boolean;
    rows: SalesSummary[];
    paginator?: Paginator<SalesSummary>;
    builderOpen: boolean;
    editingId: number | null;
    currentPermissions?: SalesDocument['permissions'];
    loadingId: number | null;
    previewNumber: string;
    mobilePane: 'fields' | 'preview';
    setMobilePane: Dispatch<SetStateAction<'fields' | 'preview'>>;
    returnFocusRef: RefObject<HTMLElement | null>;
    canEditCurrent: boolean;
    selectableClients: Option[];
    visibleProjects: Option[];
    totals: ReturnType<typeof calculateInvoiceTotals>;
    previewClient?: InvoiceClientPreview | null;
    previewCompany?: InvoiceCompanyPreview | null;
    form: InertiaFormProps<InvoiceFormData>;
    openNew: (opener: HTMLElement) => void;
    openExisting: (id: number, opener: HTMLElement) => Promise<void>;
    updateLine: (index: number, key: keyof LineItem, value: string) => void;
    submit: (event: FormEvent<HTMLFormElement>) => void;
    duplicateTemplate: () => void;
    changeArchiveState: (action: 'archive' | 'restore') => void;
    closeBuilder: () => void;
    confirmDiscard: () => boolean;
};

export function SalesWorkspace(props: SalesWorkspaceProps) {
    const {
        filters,
        projects,
        canCreate,
        rows,
        paginator,
        builderOpen,
        editingId,
        currentPermissions,
        loadingId,
        previewNumber,
        mobilePane,
        setMobilePane,
        returnFocusRef,
        canEditCurrent,
        selectableClients,
        visibleProjects,
        totals,
        previewClient,
        previewCompany,
        form,
        openNew,
        openExisting,
        updateLine,
        submit,
        duplicateTemplate,
        changeArchiveState,
        closeBuilder,
        confirmDiscard,
    } = props;

    return (
        <>
            <Head title="قوالب الفواتير" />
            <div className="cloudtech-page sales-page">
                <header className="cloudtech-page-head">
                    <div>
                        <p className="cloudtech-eyebrow">منشئ المستندات</p>
                        <h1 tabIndex={-1}>قوالب الفواتير</h1>
                        <p>
                            صمّم قالب فاتورة بهوية CloudTech، احسب بنوده داخل
                            النموذج، ثم نزّل معاينة PDF. لا يتتبع هذا القسم
                            الدفعات أو الأرصدة.
                        </p>
                    </div>
                    {canCreate && (
                        <button
                            type="button"
                            className="cloudtech-primary-action"
                            onClick={(event) => openNew(event.currentTarget)}
                        >
                            <Plus aria-hidden="true" />
                            قالب فاتورة جديد
                        </button>
                    )}
                </header>

                <aside
                    className="sales-template-notice"
                    aria-label="نطاق القسم"
                >
                    <FileText aria-hidden="true" />
                    <div>
                        <strong>قوالب فقط</strong>
                        <p>
                            القيم المعروضة أمثلة داخل القالب وليست قيوداً
                            محاسبية أو مطالبات دفع.
                        </p>
                    </div>
                </aside>

                <form
                    className="cloudtech-filter-bar"
                    action="/sales"
                    method="get"
                >
                    <label className="cloudtech-filter-search">
                        <span className="sr-only">البحث في قوالب الفواتير</span>
                        <Search aria-hidden="true" />
                        <input
                            name="q"
                            defaultValue={filters?.q}
                            placeholder="اسم القالب، الرقم، العميل أو المشروع…"
                        />
                    </label>
                    <label>
                        <span className="sr-only">حالة الأرشفة</span>
                        <select
                            name="status"
                            defaultValue={filters?.status || ''}
                        >
                            <option value="">القوالب النشطة</option>
                            <option value="archived">القوالب المؤرشفة</option>
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">المشروع النموذجي</span>
                        <select
                            name="project"
                            defaultValue={filters?.project || ''}
                        >
                            <option value="">كل المشاريع</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button type="submit">تطبيق الفلاتر</button>
                    {(filters?.q || filters?.status || filters?.project) && (
                        <Link href="/sales">مسح</Link>
                    )}
                </form>

                {rows.length === 0 ? (
                    <section
                        className="cloudtech-empty-state"
                        aria-labelledby="invoice-templates-empty-title"
                    >
                        <div className="cloudtech-empty-icon">
                            <FileText aria-hidden="true" />
                        </div>
                        <p className="cloudtech-empty-kicker">جاهز للتصميم</p>
                        <h2 id="invoice-templates-empty-title">
                            لا توجد قوالب بهذه المعايير
                        </h2>
                        <p>
                            أنشئ أول قالب فاتورة أو غيّر معايير البحث الحالية.
                        </p>
                    </section>
                ) : (
                    <section
                        className="sales-document-list"
                        aria-label="قوالب الفواتير"
                    >
                        <div className="sales-list-head" aria-hidden="true">
                            <span>القالب</span>
                            <span>بيانات المعاينة</span>
                            <span>تاريخ النموذج</span>
                            <span>المجموع النموذجي</span>
                            <span />
                        </div>
                        {rows.map((document) => (
                            <article
                                className="sales-document-row"
                                key={document.id}
                            >
                                <div className="sales-document-identity">
                                    <span className="sales-document-icon">
                                        <FileText aria-hidden="true" />
                                    </span>
                                    <div>
                                        <bdi
                                            dir="ltr"
                                            className="dashboard-code"
                                        >
                                            {document.number}
                                        </bdi>
                                        <h2>{document.title}</h2>
                                        <small>
                                            {document.status === 'archived'
                                                ? 'قالب مؤرشف'
                                                : 'قالب فاتورة'}
                                        </small>
                                    </div>
                                </div>
                                <div>
                                    <strong>
                                        {document.client || 'عميل اختياري'}
                                    </strong>
                                    <small>
                                        {document.project || 'دون مشروع'}
                                    </small>
                                </div>
                                <div>
                                    <span>
                                        <bdi dir="ltr">
                                            {formatDate(document.issueDate)}
                                        </bdi>
                                    </span>
                                    <small>
                                        {document.dueDate
                                            ? `تاريخ إضافي: ${formatDate(document.dueDate)}`
                                            : 'دون تاريخ إضافي'}
                                    </small>
                                </div>
                                <div className="sales-row-total" dir="ltr">
                                    {document.totals
                                        ? `${moneyFormatter.format(Number(document.totals.total))} ${document.currency}`
                                        : '—'}
                                </div>
                                <div className="sales-row-actions">
                                    <a
                                        href={`/sales/${document.id}/pdf`}
                                        className="sales-open-button"
                                        aria-label={`تنزيل معاينة PDF للقالب ${document.number}`}
                                        title="تنزيل معاينة PDF"
                                    >
                                        <FileDown aria-hidden="true" />
                                    </a>
                                    <button
                                        type="button"
                                        className="sales-open-button"
                                        onClick={(event) =>
                                            void openExisting(
                                                document.id,
                                                event.currentTarget,
                                            )
                                        }
                                        disabled={loadingId !== null}
                                        aria-label={`فتح قالب ${document.number}`}
                                    >
                                        {loadingId === document.id ? (
                                            <LoaderCircle
                                                className="animate-spin"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <ArrowLeft aria-hidden="true" />
                                        )}
                                    </button>
                                </div>
                            </article>
                        ))}
                    </section>
                )}

                {paginator &&
                    (paginator.prev_page_url || paginator.next_page_url) && (
                        <nav
                            className="cloudtech-pagination"
                            aria-label="صفحات القوالب"
                        >
                            {paginator.prev_page_url ? (
                                <Link href={paginator.prev_page_url}>
                                    السابق
                                </Link>
                            ) : (
                                <span>السابق</span>
                            )}
                            <span>
                                {paginator.current_page} من أصل{' '}
                                {paginator.last_page}
                            </span>
                            {paginator.next_page_url ? (
                                <Link href={paginator.next_page_url}>
                                    التالي
                                </Link>
                            ) : (
                                <span>التالي</span>
                            )}
                        </nav>
                    )}
            </div>

            <Dialog
                open={builderOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        closeBuilder();
                    }
                }}
            >
                <DialogContent
                    className="sales-builder-dialog"
                    dir="rtl"
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        window.setTimeout(() =>
                            returnFocusRef.current?.focus(),
                        );
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {editingId
                                ? 'تحرير قالب الفاتورة'
                                : 'قالب فاتورة جديد'}
                        </DialogTitle>
                        <DialogDescription>
                            أدخل محتوى نموذجياً وشاهد انعكاسه مباشرة على صفحة
                            A4. الحقول المرتبطة بعميل أو مشروع اختيارية.
                        </DialogDescription>
                    </DialogHeader>

                    <div
                        className="sales-builder-mobile-tabs"
                        role="tablist"
                        aria-label="أقسام منشئ القالب"
                    >
                        <button
                            id="sales-builder-fields-tab"
                            type="button"
                            role="tab"
                            aria-selected={mobilePane === 'fields'}
                            aria-controls="sales-builder-fields-panel"
                            onClick={() => setMobilePane('fields')}
                        >
                            البيانات والبنود
                        </button>
                        <button
                            id="sales-builder-preview-tab"
                            type="button"
                            role="tab"
                            aria-selected={mobilePane === 'preview'}
                            aria-controls="sales-builder-preview-panel"
                            onClick={() => setMobilePane('preview')}
                        >
                            معاينة A4
                        </button>
                    </div>

                    <div className="sales-builder-layout">
                        <form
                            id="sales-builder-fields-panel"
                            role="tabpanel"
                            aria-labelledby="sales-builder-fields-tab"
                            className={`sales-builder-fields ${mobilePane === 'fields' ? 'is-mobile-active' : ''}`}
                            onSubmit={submit}
                        >
                            <fieldset
                                disabled={!canEditCurrent || form.processing}
                            >
                                <legend>بيانات الفاتورة</legend>
                                <label>
                                    <span>عنوان الفاتورة *</span>
                                    <input
                                        required
                                        autoFocus
                                        value={form.data.title}
                                        onChange={(event) =>
                                            form.setData(
                                                'title',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            form.errors.title,
                                        )}
                                    />
                                    <InputError message={form.errors.title} />
                                </label>
                                <div className="cloudtech-form-grid two-columns">
                                    <label>
                                        <span>
                                            اسم الشركة / العميل (اختياري)
                                        </span>
                                        <select
                                            value={form.data.client_id}
                                            onChange={(event) => {
                                                form.setData(
                                                    'client_id',
                                                    event.target.value,
                                                );
                                                form.setData('project_id', '');
                                            }}
                                        >
                                            <option value="">دون عميل</option>
                                            {selectableClients.map((client) => (
                                                <option
                                                    key={client.id}
                                                    value={client.id}
                                                >
                                                    {client.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={form.errors.client_id}
                                        />
                                    </label>
                                    <label>
                                        <span>مشروع للمعاينة (اختياري)</span>
                                        <select
                                            value={form.data.project_id}
                                            onChange={(event) =>
                                                form.setData(
                                                    'project_id',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">دون مشروع</option>
                                            {visibleProjects.map((project) => (
                                                <option
                                                    key={project.id}
                                                    value={project.id}
                                                >
                                                    {project.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={form.errors.project_id}
                                        />
                                    </label>
                                </div>
                                <div className="cloudtech-form-grid two-columns">
                                    <label>
                                        <span>تاريخ الإصدار (اختياري)</span>
                                        <input
                                            type="date"
                                            value={form.data.issue_date}
                                            onChange={(event) =>
                                                form.setData(
                                                    'issue_date',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.issue_date}
                                        />
                                    </label>
                                    <label>
                                        <span>تاريخ الاستحقاق (اختياري)</span>
                                        <input
                                            type="date"
                                            min={form.data.issue_date}
                                            value={form.data.due_date}
                                            onChange={(event) =>
                                                form.setData(
                                                    'due_date',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.due_date}
                                        />
                                    </label>
                                </div>
                                <div className="cloudtech-form-grid two-columns">
                                    <label>
                                        <span>المرجع (اختياري)</span>
                                        <input
                                            value={form.data.reference}
                                            onChange={(event) =>
                                                form.setData(
                                                    'reference',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                    <label>
                                        <span>العملة *</span>
                                        <select
                                            value={form.data.currency}
                                            onChange={(event) =>
                                                form.setData(
                                                    'currency',
                                                    event.target.value as
                                                        'LYD' | 'USD' | 'EUR',
                                                )
                                            }
                                        >
                                            <option value="LYD">
                                                دينار ليبي (LYD)
                                            </option>
                                            <option value="USD">
                                                دولار أمريكي (USD)
                                            </option>
                                            <option value="EUR">
                                                يورو (EUR)
                                            </option>
                                        </select>
                                    </label>
                                </div>
                            </fieldset>

                            <fieldset className="sales-line-items">
                                <legend>الخدمات والكميات</legend>
                                {form.data.line_items.map((item, index) => (
                                    <section
                                        className="sales-line-item"
                                        key={index}
                                        aria-labelledby={`invoice-line-${index}`}
                                    >
                                        <header>
                                            <strong
                                                id={`invoice-line-${index}`}
                                            >
                                                البند {index + 1}
                                            </strong>
                                            <button
                                                type="button"
                                                disabled={
                                                    !canEditCurrent ||
                                                    form.data.line_items
                                                        .length === 1
                                                }
                                                onClick={() =>
                                                    form.setData(
                                                        'line_items',
                                                        form.data.line_items.filter(
                                                            (_, itemIndex) =>
                                                                itemIndex !==
                                                                index,
                                                        ),
                                                    )
                                                }
                                                aria-label={`حذف البند ${index + 1}`}
                                            >
                                                <Trash2 aria-hidden="true" />
                                            </button>
                                        </header>
                                        <label>
                                            <span>اسم البند *</span>
                                            <input
                                                required
                                                value={item.name}
                                                onChange={(event) =>
                                                    updateLine(
                                                        index,
                                                        'name',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    form.errors[
                                                        `line_items.${index}.name`
                                                    ]
                                                }
                                            />
                                        </label>
                                        <label>
                                            <span>وصف مختصر</span>
                                            <textarea
                                                rows={2}
                                                value={item.description}
                                                onChange={(event) =>
                                                    updateLine(
                                                        index,
                                                        'description',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <div className="cloudtech-form-grid three-columns">
                                            <label>
                                                <span>الكمية *</span>
                                                <input
                                                    required
                                                    min="0.001"
                                                    step="0.001"
                                                    type="number"
                                                    inputMode="decimal"
                                                    value={item.quantity}
                                                    onChange={(event) =>
                                                        updateLine(
                                                            index,
                                                            'quantity',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                            <label>
                                                <span>الوحدة *</span>
                                                <select
                                                    value={item.unit}
                                                    onChange={(event) =>
                                                        updateLine(
                                                            index,
                                                            'unit',
                                                            event.target.value,
                                                        )
                                                    }
                                                >
                                                    {unitOptions.map((unit) => (
                                                        <option
                                                            key={unit}
                                                            value={unit}
                                                        >
                                                            {unit}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                            <label>
                                                <span>السعر *</span>
                                                <input
                                                    required
                                                    min="0"
                                                    step="0.01"
                                                    type="number"
                                                    inputMode="decimal"
                                                    value={item.unit_price}
                                                    onChange={(event) =>
                                                        updateLine(
                                                            index,
                                                            'unit_price',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                        </div>
                                    </section>
                                ))}
                                <button
                                    type="button"
                                    className="sales-add-line"
                                    disabled={!canEditCurrent}
                                    onClick={() =>
                                        form.setData('line_items', [
                                            ...form.data.line_items,
                                            initialLine(),
                                        ])
                                    }
                                >
                                    <Plus aria-hidden="true" /> إضافة بند
                                </button>
                                <InputError message={form.errors.line_items} />
                            </fieldset>

                            <fieldset
                                disabled={!canEditCurrent || form.processing}
                            >
                                <legend>القيمة المالية</legend>
                                <p className="sales-fieldset-note">
                                    هذه الحسابات تظهر داخل الفاتورة فقط ولا تنشئ
                                    رصيداً أو حركة مالية.
                                </p>
                                <div className="cloudtech-form-grid two-columns">
                                    <label>
                                        <span>الخصم %</span>
                                        <input
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            type="number"
                                            value={form.data.discount_rate}
                                            onChange={(event) =>
                                                form.setData(
                                                    'discount_rate',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                    <label>
                                        <span>الضريبة %</span>
                                        <input
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            type="number"
                                            value={form.data.tax_rate}
                                            onChange={(event) =>
                                                form.setData(
                                                    'tax_rate',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                </div>
                                <label>
                                    <span>ملاحظة الأسعار</span>
                                    <textarea
                                        rows={3}
                                        value={form.data.notes}
                                        onChange={(event) =>
                                            form.setData(
                                                'notes',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <InputError
                                    message={
                                        form.errors.type ||
                                        form.errors.status ||
                                        form.errors.lock_version
                                    }
                                />
                            </fieldset>

                            <footer className="sales-builder-actions">
                                <button
                                    type="button"
                                    className="sales-cancel"
                                    onClick={closeBuilder}
                                >
                                    <X aria-hidden="true" /> إغلاق
                                </button>
                                {editingId && currentPermissions?.duplicate && (
                                    <button
                                        type="button"
                                        className="sales-secondary"
                                        onClick={duplicateTemplate}
                                    >
                                        <Copy aria-hidden="true" /> إنشاء نسخة
                                    </button>
                                )}
                                {editingId && currentPermissions?.archive && (
                                    <button
                                        type="button"
                                        className="sales-secondary"
                                        onClick={() =>
                                            changeArchiveState('archive')
                                        }
                                    >
                                        <Archive aria-hidden="true" /> أرشفة
                                        القالب
                                    </button>
                                )}
                                {editingId && currentPermissions?.restore && (
                                    <button
                                        type="button"
                                        className="sales-secondary"
                                        onClick={() =>
                                            changeArchiveState('restore')
                                        }
                                    >
                                        <RotateCcw aria-hidden="true" /> استعادة
                                        القالب
                                    </button>
                                )}
                                {editingId && (
                                    <a
                                        className="sales-secondary"
                                        href={`/sales/${editingId}/pdf`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        onClick={(event) => {
                                            if (!confirmDiscard()) {
                                                event.preventDefault();
                                            }
                                        }}
                                    >
                                        <FileDown aria-hidden="true" /> معاينة
                                        PDF
                                    </a>
                                )}
                                {canEditCurrent && (
                                    <button
                                        type="submit"
                                        className="cloudtech-primary-action"
                                        disabled={form.processing}
                                    >
                                        {form.processing ? (
                                            <LoaderCircle
                                                className="animate-spin"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <Check aria-hidden="true" />
                                        )}{' '}
                                        حفظ القالب
                                    </button>
                                )}
                                <span className="sr-only" aria-live="polite">
                                    {form.processing ? 'جار حفظ القالب' : ''}
                                </span>
                            </footer>
                        </form>

                        <aside
                            id="sales-builder-preview-panel"
                            role="tabpanel"
                            aria-labelledby="sales-builder-preview-tab"
                            className={`sales-preview-panel ${mobilePane === 'preview' ? 'is-mobile-active' : ''}`}
                            aria-label="معاينة قالب الفاتورة"
                        >
                            <InvoiceTemplatePreview
                                title={form.data.title}
                                number={previewNumber}
                                reference={form.data.reference}
                                issueDate={form.data.issue_date}
                                dueDate={form.data.due_date}
                                currency={form.data.currency}
                                discountRate={form.data.discount_rate}
                                taxRate={form.data.tax_rate}
                                notes={form.data.notes}
                                items={form.data.line_items}
                                totals={totals}
                                client={previewClient}
                                company={previewCompany}
                                formatMoney={(value) =>
                                    moneyFormatter.format(value)
                                }
                            />
                        </aside>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

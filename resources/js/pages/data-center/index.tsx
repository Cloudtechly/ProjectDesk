import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardCheck,
    CloudDownload,
    Database,
    DatabaseBackup,
    FileDown,
    FileSearch,
    FileSpreadsheet,
    FileUp,
    History,
    LoaderCircle,
    RefreshCw,
    RotateCcw,
    ShieldCheck,
    UploadCloud,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { createLocaleDateTimeFormatter } from '@/i18n/formatters';

type DataCenterTab = 'import' | 'export' | 'backup';
type ImportResource = 'clients' | 'tasks';
type ExportResource = ImportResource | 'projects';
type ImportFormat = 'xlsx' | 'csv';

type ImportErrorItem = {
    id?: number;
    sheet?: string | null;
    row_number?: number | null;
    field?: string | null;
    code?: string;
    message: string;
};

type FileObject = {
    id: number;
    original_name: string;
    size_bytes: number;
    checksum_sha256: string;
};

type DataJob = {
    id: number;
    type: string;
    resource_type?: string | null;
    format?: string | null;
    status: string;
    file_object_id?: number | null;
    file_object?: FileObject | null;
    summary?: {
        checksum_sha256?: string;
        row_count?: number;
        valid_count?: number;
        error_count?: number;
        committed_count?: number;
        can_commit?: boolean;
        contains_current_admin?: boolean;
        quick_check?: string;
        encrypted?: boolean;
        cipher?: string;
        key_id?: string;
        files_complete?: boolean;
        files_count?: number;
        files_size_bytes?: number;
        files_restored?: number;
        restore_nonce?: string;
        actions?: { create?: number; update?: number };
        preview?: Array<Record<string, unknown>>;
    } | null;
    import_errors?: ImportErrorItem[];
    error_message?: string | null;
    created_at?: string;
    completed_at?: string | null;
};

type BackupValidationResult = {
    encrypted?: boolean;
    cipher?: string;
    key_id?: string;
    files_complete?: boolean;
    files_count?: number;
    files_size_bytes?: number;
    restore_nonce: string;
};

type BackupRestoreResult = {
    files_restored?: number;
};

const resourceLabels: Record<ExportResource, string> = {
    clients: 'العملاء',
    projects: 'المشاريع',
    tasks: 'المهام',
};

const jobLabels: Record<string, string> = {
    import: 'استيراد',
    export: 'تصدير',
    backup: 'نسخة احتياطية',
    backup_upload: 'نسخة مرفوعة',
    restore: 'استعادة نسخة',
};

const statusLabels: Record<string, string> = {
    processing: 'قيد التنفيذ',
    validated: 'صالحة للاستيراد',
    validation_failed: 'تحتاج تصحيحاً',
    succeeded: 'اكتملت',
    failed: 'فشلت',
};

const dateFormatter = createLocaleDateTimeFormatter({
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Africa/Tripoli',
});

function csrfToken() {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

async function requestJson<T>(url: string, init?: RequestInit): Promise<T> {
    const isFormData = init?.body instanceof FormData;
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
            ...init?.headers,
        },
    });
    const payload = (await response.json().catch(() => null)) as
        | {
              data?: T;
              message?: string;
              errors?: Record<string, string[]>;
          }
        | T
        | null;

    if (!response.ok) {
        const envelope = payload as {
            message?: string;
            errors?: Record<string, string[]>;
        } | null;
        const validation = envelope?.errors
            ? Object.values(envelope.errors).flat()[0]
            : undefined;

        throw new Error(
            validation || envelope?.message || 'تعذر إتمام العملية.',
        );
    }

    if (payload && typeof payload === 'object' && 'data' in payload) {
        return (payload as { data: T }).data;
    }

    return payload as T;
}

function formatDate(value?: string | null) {
    if (!value) {
        return 'غير محدد';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

function formatBytes(value?: number) {
    if (!value || value < 1) {
        return '—';
    }

    if (value < 1024 * 1024) {
        return `${Math.ceil(value / 1024)} KB`;
    }

    return `${(value / 1024 / 1024).toFixed(1)} MB`;
}

export default function DataCenterIndex() {
    const [tab, setTab] = useState<DataCenterTab>('import');
    const [resource, setResource] = useState<ImportResource>('clients');
    const [importFormat, setImportFormat] = useState<ImportFormat>('xlsx');
    const [file, setFile] = useState<File | null>(null);
    const [importJob, setImportJob] = useState<DataJob | null>(null);
    const [jobs, setJobs] = useState<DataJob[]>([]);
    const [selectedBackup, setSelectedBackup] = useState<DataJob | null>(null);
    const [restoreConfirmation, setRestoreConfirmation] = useState('');
    const [busy, setBusy] = useState<string | null>(null);
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');
    const [jobsLoading, setJobsLoading] = useState(true);

    useEffect(() => {
        let cancelled = false;

        void requestJson<DataJob[]>('/data-center/jobs?per_page=30')
            .then((result) => {
                if (!cancelled) {
                    setJobs(result);
                }
            })
            .catch((requestError: unknown) => {
                if (!cancelled) {
                    setError(
                        requestError instanceof Error
                            ? requestError.message
                            : 'تعذر تحميل سجل العمليات.',
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setJobsLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, []);

    const backupJobs = useMemo(
        () =>
            jobs.filter(
                (job) =>
                    (job.type === 'backup' || job.type === 'backup_upload') &&
                    job.status === 'succeeded' &&
                    job.file_object,
            ),
        [jobs],
    );

    async function refreshJobs() {
        setJobsLoading(true);
        setError('');

        try {
            const result = await requestJson<DataJob[]>(
                '/data-center/jobs?per_page=30',
            );
            setJobs(result);
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر تحديث سجل العمليات.',
            );
        } finally {
            setJobsLoading(false);
        }
    }

    async function previewImport() {
        if (!file) {
            setError('اختر ملف Excel أو CSV قبل بدء الفحص.');

            return;
        }

        setBusy('preview');
        setError('');
        setNotice('');
        const body = new FormData();
        body.append('file', file);

        try {
            const job = await requestJson<DataJob>(
                `/data-center/${importFormat}/${resource}/preview`,
                { method: 'POST', body },
            );
            setImportJob(job);
            setNotice(
                job.status === 'validated'
                    ? 'تم فحص الملف، راجع المعاينة ثم نفّذ الاستيراد.'
                    : 'اكتمل الفحص ووجد النظام أخطاء تحتاج إلى التصحيح.',
            );
            setJobs((current) => [job, ...current]);
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر فحص ملف الاستيراد.',
            );
        } finally {
            setBusy(null);
        }
    }

    async function commitImport() {
        const checksum = importJob?.summary?.checksum_sha256;

        if (!importJob || !checksum || !importJob.summary?.can_commit) {
            return;
        }

        setBusy('commit');
        setError('');
        setNotice('');

        try {
            const job = await requestJson<DataJob>(
                `/data-center/imports/${importJob.id}/commit`,
                {
                    method: 'POST',
                    body: JSON.stringify({ checksum_sha256: checksum }),
                },
            );
            setImportJob(job);
            setNotice(
                `تم استيراد ${job.summary?.committed_count ?? 0} سجلاً بنجاح.`,
            );
            await refreshJobs();
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر تنفيذ الاستيراد.',
            );
        } finally {
            setBusy(null);
        }
    }

    async function createBackup() {
        setBusy('backup');
        setError('');
        setNotice('');

        try {
            const job = await requestJson<DataJob>('/data-center/backups', {
                method: 'POST',
                body: JSON.stringify({}),
            });
            setJobs((current) => [job, ...current]);
            setSelectedBackup(job);
            setNotice('أُنشئت نسخة احتياطية وتحقق النظام من سلامتها.');
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'فشل إنشاء النسخة الاحتياطية.',
            );
        } finally {
            setBusy(null);
        }
    }

    async function uploadBackup(upload: File) {
        setBusy('backup-upload');
        setError('');
        setNotice('');
        const body = new FormData();
        body.append('file', upload);

        try {
            const job = await requestJson<DataJob>(
                '/data-center/backups/upload',
                { method: 'POST', body },
            );
            setJobs((current) => [job, ...current]);
            setSelectedBackup(job);
            setNotice('تم رفع النسخة وفحص بنيتها وبصمتها بنجاح.');
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'ملف النسخة غير صالح.',
            );
        } finally {
            setBusy(null);
        }
    }

    async function validateBackup(job: DataJob) {
        const fileObject = job.file_object;

        if (!fileObject) {
            return;
        }

        setBusy(`validate-${job.id}`);
        setError('');

        try {
            const validation = await requestJson<BackupValidationResult>(
                `/data-center/backups/${fileObject.id}/validate`,
                { method: 'POST', body: JSON.stringify({}) },
            );
            const validatedJob = {
                ...job,
                summary: { ...job.summary, ...validation },
            };
            setJobs((current) =>
                current.map((item) =>
                    item.id === job.id ? validatedJob : item,
                ),
            );
            setSelectedBackup(validatedJob);
            setNotice('النسخة سليمة وجاهزة للاستعادة عند التأكيد.');
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر التحقق من النسخة.',
            );
        } finally {
            setBusy(null);
        }
    }

    async function restoreBackup() {
        const fileObject = selectedBackup?.file_object;

        if (!fileObject) {
            return;
        }

        if (
            !window.confirm(
                'ستُستبدل البيانات الحالية بالكامل، وسيُنشئ النظام نسخة أمان قبل الاستعادة. هل تريد المتابعة؟',
            )
        ) {
            return;
        }

        setBusy('restore');
        setError('');
        setNotice('');

        try {
            const result = await requestJson<BackupRestoreResult>(
                `/data-center/backups/${fileObject.id}/restore`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        confirmation: restoreConfirmation,
                        checksum_sha256: fileObject.checksum_sha256,
                        restore_nonce: selectedBackup.summary?.restore_nonce,
                    }),
                },
            );
            setNotice(
                `اكتملت الاستعادة${result.files_restored !== undefined ? ` واستعيد ${result.files_restored} ملفاً` : ''}. سيعاد تحميل النظام للتأكد من الجلسة والبيانات.`,
            );
            window.setTimeout(() => window.location.reload(), 1200);
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'فشلت الاستعادة وبقيت البيانات السابقة آمنة.',
            );
        } finally {
            setBusy(null);
        }
    }

    return (
        <>
            <Head title="مركز البيانات" />
            <div className="data-center-page">
                <header className="cloudtech-page-head">
                    <div>
                        <span className="cloudtech-eyebrow">إدارة موثوقة</span>
                        <h1 tabIndex={-1}>مركز البيانات</h1>
                        <p>
                            استورد البيانات بقوالب ثابتة، صدّر نسخة قابلة
                            للمراجعة، وأدر النسخ الاحتياطية الخاصة بالنظام.
                        </p>
                    </div>
                    <span className="data-center-simulation-badge">
                        <ShieldCheck aria-hidden="true" />
                        عمليات حقيقية ومسجلة
                    </span>
                </header>

                <div className="sr-only" aria-live="polite" aria-atomic="true">
                    {notice || error}
                </div>
                {error && (
                    <div className="cloudtech-alert danger" role="alert">
                        <AlertTriangle aria-hidden="true" />
                        <span>{error}</span>
                        <button type="button" onClick={() => setError('')}>
                            إغلاق
                        </button>
                    </div>
                )}
                {notice && (
                    <div className="cloudtech-alert success" role="status">
                        <CheckCircle2 aria-hidden="true" />
                        <span>{notice}</span>
                    </div>
                )}

                <nav
                    className="data-center-tabs"
                    aria-label="أدوات مركز البيانات"
                >
                    {(
                        [
                            ['import', 'الاستيراد', FileUp],
                            ['export', 'التصدير', FileDown],
                            ['backup', 'النسخ والاستعادة', DatabaseBackup],
                        ] as const
                    ).map(([id, label, Icon]) => (
                        <button
                            key={id}
                            type="button"
                            aria-current={tab === id ? 'page' : undefined}
                            onClick={() => setTab(id)}
                        >
                            <Icon aria-hidden="true" />
                            {label}
                        </button>
                    ))}
                </nav>

                {tab === 'import' && (
                    <section
                        className="data-center-panel"
                        aria-labelledby="import-title"
                    >
                        <header>
                            <div>
                                <span className="cloudtech-eyebrow">
                                    أربع خطوات واضحة
                                </span>
                                <h2 id="import-title">استيراد Excel وCSV</h2>
                                <p>
                                    لا تُحفظ أي بيانات قبل نجاح الفحص وموافقتك
                                    النهائية.
                                </p>
                            </div>
                            <ol
                                className="data-import-steps"
                                aria-label="خطوات الاستيراد"
                            >
                                <li className="is-done">1. النوع والقالب</li>
                                <li className={file ? 'is-done' : ''}>
                                    2. الملف
                                </li>
                                <li className={importJob ? 'is-done' : ''}>
                                    3. الفحص
                                </li>
                                <li
                                    className={
                                        importJob?.status === 'succeeded'
                                            ? 'is-done'
                                            : ''
                                    }
                                >
                                    4. النتيجة
                                </li>
                            </ol>
                        </header>

                        <div className="data-import-layout">
                            <div className="data-import-controls">
                                <label>
                                    <span>نوع البيانات</span>
                                    <select
                                        value={resource}
                                        onChange={(event) => {
                                            setResource(
                                                event.currentTarget
                                                    .value as ImportResource,
                                            );
                                            setImportJob(null);
                                        }}
                                    >
                                        <option value="clients">العملاء</option>
                                        <option value="tasks">المهام</option>
                                    </select>
                                </label>
                                <label>
                                    <span>صيغة الملف</span>
                                    <select
                                        value={importFormat}
                                        onChange={(event) => {
                                            setImportFormat(
                                                event.currentTarget
                                                    .value as ImportFormat,
                                            );
                                            setFile(null);
                                            setImportJob(null);
                                        }}
                                    >
                                        <option value="xlsx">
                                            Excel (.xlsx)
                                        </option>
                                        <option value="csv">CSV (.csv)</option>
                                    </select>
                                </label>
                                <a
                                    className="data-center-link-action"
                                    href={`/data-center/${importFormat}/${resource}/template`}
                                >
                                    <CloudDownload aria-hidden="true" />
                                    تنزيل قالب {resourceLabels[resource]}{' '}
                                    {importFormat.toUpperCase()}
                                </a>
                                <label className="data-import-dropzone">
                                    <UploadCloud aria-hidden="true" />
                                    <strong>
                                        {file
                                            ? file.name
                                            : `اختر ملف ${importFormat === 'xlsx' ? 'Excel' : 'CSV'}`}
                                    </strong>
                                    <span>
                                        حتى 10MB · ورقة واحدة · القالب الثابت
                                        فقط
                                    </span>
                                    <input
                                        type="file"
                                        accept={
                                            importFormat === 'xlsx'
                                                ? '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                                                : '.csv,text/csv'
                                        }
                                        onChange={(event) => {
                                            setFile(
                                                event.currentTarget
                                                    .files?.[0] ?? null,
                                            );
                                            setImportJob(null);
                                        }}
                                    />
                                </label>
                                <button
                                    className="cloudtech-primary-action"
                                    type="button"
                                    disabled={!file || busy !== null}
                                    onClick={() => void previewImport()}
                                >
                                    {busy === 'preview' ? (
                                        <LoaderCircle
                                            aria-hidden="true"
                                            className="is-spinning"
                                        />
                                    ) : (
                                        <FileSearch aria-hidden="true" />
                                    )}
                                    {busy === 'preview'
                                        ? 'جارٍ الفحص…'
                                        : 'فحص ومعاينة الملف'}
                                </button>
                            </div>

                            <div className="data-import-result">
                                {!importJob ? (
                                    <div className="data-center-empty">
                                        <ClipboardCheck aria-hidden="true" />
                                        <strong>ستظهر نتيجة الفحص هنا</strong>
                                        <span>
                                            يعرض النظام الصفوف الصالحة والأخطاء
                                            ومواقعها قبل الاستيراد.
                                        </span>
                                    </div>
                                ) : (
                                    <>
                                        <div className="data-import-summary">
                                            <article>
                                                <span>الصفوف</span>
                                                <strong>
                                                    {importJob.summary
                                                        ?.row_count ?? 0}
                                                </strong>
                                            </article>
                                            <article>
                                                <span>صالحة</span>
                                                <strong>
                                                    {importJob.summary
                                                        ?.valid_count ?? 0}
                                                </strong>
                                            </article>
                                            <article
                                                className={
                                                    importJob.summary
                                                        ?.error_count
                                                        ? 'is-danger'
                                                        : ''
                                                }
                                            >
                                                <span>الأخطاء</span>
                                                <strong>
                                                    {importJob.summary
                                                        ?.error_count ?? 0}
                                                </strong>
                                            </article>
                                            <article>
                                                <span>جديدة / تحديث</span>
                                                <strong>
                                                    {importJob.summary?.actions
                                                        ?.create ?? 0}{' '}
                                                    /{' '}
                                                    {importJob.summary?.actions
                                                        ?.update ?? 0}
                                                </strong>
                                            </article>
                                        </div>

                                        {(importJob.import_errors?.length ??
                                            0) > 0 && (
                                            <div className="data-import-errors">
                                                <h3>
                                                    الأخطاء التي يجب تصحيحها
                                                </h3>
                                                <ul>
                                                    {importJob.import_errors
                                                        ?.slice(0, 12)
                                                        .map((item, index) => (
                                                            <li
                                                                key={
                                                                    item.id ??
                                                                    `${item.code}-${index}`
                                                                }
                                                            >
                                                                <bdi dir="ltr">
                                                                    {item.sheet
                                                                        ? `${item.sheet} · `
                                                                        : ''}
                                                                    {item.row_number
                                                                        ? `صف ${item.row_number}`
                                                                        : 'الملف'}
                                                                    {item.field
                                                                        ? ` · ${item.field}`
                                                                        : ''}
                                                                </bdi>
                                                                <span>
                                                                    {
                                                                        item.message
                                                                    }
                                                                </span>
                                                            </li>
                                                        ))}
                                                </ul>
                                            </div>
                                        )}

                                        {(importJob.summary?.preview?.length ??
                                            0) > 0 && (
                                            <div className="data-import-preview">
                                                <h3>معاينة أول الصفوف</h3>
                                                <div>
                                                    {importJob.summary?.preview
                                                        ?.slice(0, 5)
                                                        .map((row, index) => (
                                                            <article
                                                                key={index}
                                                            >
                                                                <strong>
                                                                    صف{' '}
                                                                    {String(
                                                                        row._row_number ??
                                                                            index +
                                                                                2,
                                                                    )}
                                                                </strong>
                                                                <span>
                                                                    {String(
                                                                        row.name ??
                                                                            row.title ??
                                                                            row.code ??
                                                                            'سجل بيانات',
                                                                    )}
                                                                </span>
                                                                <small>
                                                                    {String(
                                                                        row._action ===
                                                                            'update'
                                                                            ? 'تحديث سجل قائم'
                                                                            : 'إنشاء سجل جديد',
                                                                    )}
                                                                </small>
                                                            </article>
                                                        ))}
                                                </div>
                                            </div>
                                        )}

                                        <button
                                            className="cloudtech-primary-action"
                                            type="button"
                                            disabled={
                                                !importJob.summary
                                                    ?.can_commit ||
                                                busy !== null
                                            }
                                            onClick={() => void commitImport()}
                                        >
                                            {busy === 'commit' ? (
                                                <LoaderCircle
                                                    aria-hidden="true"
                                                    className="is-spinning"
                                                />
                                            ) : (
                                                <CheckCircle2 aria-hidden="true" />
                                            )}
                                            {busy === 'commit'
                                                ? 'جارٍ الاستيراد…'
                                                : 'تأكيد الاستيراد النهائي'}
                                        </button>
                                    </>
                                )}
                            </div>
                        </div>
                    </section>
                )}

                {tab === 'export' && (
                    <section
                        className="data-center-panel"
                        aria-labelledby="export-title"
                    >
                        <header>
                            <div>
                                <span className="cloudtech-eyebrow">
                                    نسخة قابلة للتحليل
                                </span>
                                <h2 id="export-title">تصدير البيانات</h2>
                                <p>
                                    نزّل ملف Excel فعلياً أو CSV بترميز UTF-8؛
                                    كلاهما يحمي الخلايا من صيغ جداول البيانات
                                    الخطرة.
                                </p>
                            </div>
                        </header>
                        <div className="data-export-grid">
                            {(
                                [
                                    'clients',
                                    'projects',
                                    'tasks',
                                ] as ExportResource[]
                            ).map((type) => (
                                <article key={type}>
                                    <FileSpreadsheet aria-hidden="true" />
                                    <div>
                                        <h3>{resourceLabels[type]}</h3>
                                        <p>
                                            جميع السجلات النشطة والحقول
                                            الأساسية.
                                        </p>
                                    </div>
                                    <div className="data-export-actions">
                                        <a
                                            href={`/data-center/xlsx/${type}/export`}
                                        >
                                            <FileDown aria-hidden="true" />
                                            تصدير Excel
                                        </a>
                                        <a
                                            href={`/data-center/csv/${type}/export`}
                                        >
                                            <FileDown aria-hidden="true" />
                                            CSV
                                        </a>
                                    </div>
                                </article>
                            ))}
                        </div>
                        <p className="data-center-note">
                            Excel مخصص للتحليل والتسليم، وCSV يبقى الصيغة
                            القابلة لإعادة الاستيراد. ملفات PDF متاحة من
                            المستندات التجارية وملخص كل مشروع.
                        </p>
                    </section>
                )}

                {tab === 'backup' && (
                    <section
                        className="data-center-panel"
                        aria-labelledby="backup-title"
                    >
                        <header>
                            <div>
                                <span className="cloudtech-eyebrow">
                                    حماية واسترداد
                                </span>
                                <h2 id="backup-title">
                                    النسخ الاحتياطي والاستعادة
                                </h2>
                                <p>
                                    تنشئ Project Desk حزمة مشفرة تشمل قاعدة
                                    البيانات والملفات، وتتحقق من سلامتها وبصمتها
                                    قبل إتاحتها.
                                </p>
                            </div>
                            <button
                                className="cloudtech-primary-action"
                                type="button"
                                disabled={busy !== null}
                                onClick={() => void createBackup()}
                            >
                                {busy === 'backup' ? (
                                    <LoaderCircle
                                        aria-hidden="true"
                                        className="is-spinning"
                                    />
                                ) : (
                                    <DatabaseBackup aria-hidden="true" />
                                )}
                                {busy === 'backup'
                                    ? 'جارٍ إنشاء النسخة…'
                                    : 'إنشاء نسخة الآن'}
                            </button>
                        </header>

                        <div className="data-backup-layout">
                            <div>
                                <h3>النسخ المتاحة</h3>
                                <label className="data-backup-upload">
                                    <UploadCloud aria-hidden="true" />
                                    <span>
                                        <strong>رفع نسخة خارجية</strong>
                                        <small>
                                            PDesk مشفرة (قاعدة + ملفات)، أو
                                            SQLite قديمة لقاعدة البيانات فقط
                                        </small>
                                    </span>
                                    <input
                                        type="file"
                                        accept=".pdesk,.sqlite,.sqlite3,.db,application/vnd.projectdesk.backup,application/vnd.sqlite3,application/x-sqlite3,application/x-sqlite"
                                        disabled={busy !== null}
                                        onChange={(event) => {
                                            const upload =
                                                event.currentTarget.files?.[0];

                                            if (upload) {
                                                void uploadBackup(upload);
                                            }

                                            event.currentTarget.value = '';
                                        }}
                                    />
                                </label>
                                <div className="data-backup-list">
                                    {backupJobs.length === 0 ? (
                                        <div className="data-center-empty compact">
                                            <Database aria-hidden="true" />
                                            <strong>لا توجد نسخ بعد</strong>
                                            <span>
                                                أنشئ أول نسخة أو ارفع ملفاً
                                                صالحاً.
                                            </span>
                                        </div>
                                    ) : (
                                        backupJobs.map((job) => (
                                            <article
                                                key={job.id}
                                                className={
                                                    selectedBackup?.id ===
                                                    job.id
                                                        ? 'is-selected'
                                                        : ''
                                                }
                                            >
                                                <DatabaseBackup aria-hidden="true" />
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setSelectedBackup(job)
                                                    }
                                                >
                                                    <strong>
                                                        {job.file_object
                                                            ?.original_name ??
                                                            `نسخة #${job.id}`}
                                                    </strong>
                                                    <small>
                                                        {formatDate(
                                                            job.completed_at ??
                                                                job.created_at,
                                                        )}{' '}
                                                        ·{' '}
                                                        {formatBytes(
                                                            job.file_object
                                                                ?.size_bytes,
                                                        )}
                                                    </small>
                                                </button>
                                                <a
                                                    href={`/data-center/backups/${job.file_object?.id}/download`}
                                                    aria-label={`تنزيل ${job.file_object?.original_name ?? 'النسخة'}`}
                                                >
                                                    <FileDown aria-hidden="true" />
                                                </a>
                                            </article>
                                        ))
                                    )}
                                </div>
                            </div>

                            <div className="data-restore-panel">
                                <RotateCcw aria-hidden="true" />
                                <h3>استعادة نسخة</h3>
                                {!selectedBackup ? (
                                    <p>
                                        اختر نسخة من القائمة لمراجعتها قبل
                                        الاستعادة.
                                    </p>
                                ) : (
                                    <>
                                        <div className="data-restore-file">
                                            <strong>
                                                {
                                                    selectedBackup.file_object
                                                        ?.original_name
                                                }
                                            </strong>
                                            <bdi dir="ltr">
                                                {selectedBackup.file_object?.checksum_sha256.slice(
                                                    0,
                                                    18,
                                                )}
                                                …
                                            </bdi>
                                        </div>
                                        <dl className="data-restore-summary">
                                            <div>
                                                <dt>الحماية</dt>
                                                <dd>
                                                    {selectedBackup.summary
                                                        ?.encrypted
                                                        ? 'مشفرة'
                                                        : 'SQLite قديمة'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>المحتوى</dt>
                                                <dd>
                                                    {selectedBackup.summary
                                                        ?.files_complete
                                                        ? 'قاعدة + ملفات'
                                                        : 'قاعدة فقط'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>الملفات</dt>
                                                <dd>
                                                    {selectedBackup.summary
                                                        ?.files_count ?? 0}{' '}
                                                    ·{' '}
                                                    {formatBytes(
                                                        selectedBackup.summary
                                                            ?.files_size_bytes,
                                                    )}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>التشفير / المفتاح</dt>
                                                <dd dir="ltr">
                                                    {selectedBackup.summary
                                                        ?.cipher ?? 'legacy'}
                                                    {selectedBackup.summary
                                                        ?.key_id
                                                        ? ` · ${selectedBackup.summary.key_id}`
                                                        : ''}
                                                </dd>
                                            </div>
                                        </dl>
                                        <button
                                            type="button"
                                            className="data-center-link-action"
                                            disabled={busy !== null}
                                            onClick={() =>
                                                void validateBackup(
                                                    selectedBackup,
                                                )
                                            }
                                        >
                                            {busy ===
                                            `validate-${selectedBackup.id}` ? (
                                                <LoaderCircle
                                                    aria-hidden="true"
                                                    className="is-spinning"
                                                />
                                            ) : (
                                                <ShieldCheck aria-hidden="true" />
                                            )}
                                            تحقق من النسخة مجدداً
                                        </button>
                                        <div className="data-restore-warning">
                                            <AlertTriangle aria-hidden="true" />
                                            <p>
                                                ستُستبدل قاعدة البيانات الحالية
                                                {selectedBackup.summary
                                                    ?.files_complete
                                                    ? ' وتُستعاد ملفات الحزمة كاملة'
                                                    : ' فقط؛ هذه نسخة SQLite قديمة لا تشمل الملفات'}
                                                . ينشئ النظام نسخة أمان تلقائياً
                                                قبل البدء، لكن يجب إيقاف عمل
                                                المستخدمين أثناء الاستعادة.
                                            </p>
                                        </div>
                                        <label>
                                            <span>
                                                اكتب{' '}
                                                <bdi dir="ltr">
                                                    RESTORE PROJECT DESK
                                                </bdi>{' '}
                                                للتأكيد
                                            </span>
                                            <input
                                                value={restoreConfirmation}
                                                dir="ltr"
                                                autoComplete="off"
                                                onChange={(event) =>
                                                    setRestoreConfirmation(
                                                        event.currentTarget
                                                            .value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <button
                                            className="data-restore-action"
                                            type="button"
                                            disabled={
                                                busy !== null ||
                                                restoreConfirmation !==
                                                    'RESTORE PROJECT DESK'
                                            }
                                            onClick={() => void restoreBackup()}
                                        >
                                            {busy === 'restore' ? (
                                                <LoaderCircle
                                                    aria-hidden="true"
                                                    className="is-spinning"
                                                />
                                            ) : (
                                                <RefreshCw aria-hidden="true" />
                                            )}
                                            {busy === 'restore'
                                                ? 'جارٍ الاستعادة…'
                                                : 'استعادة هذه النسخة'}
                                        </button>
                                    </>
                                )}
                            </div>
                        </div>
                    </section>
                )}

                <section
                    className="data-jobs-panel"
                    aria-labelledby="jobs-title"
                >
                    <header>
                        <div>
                            <History aria-hidden="true" />
                            <div>
                                <h2 id="jobs-title">سجل عمليات البيانات</h2>
                                <p>
                                    آخر عمليات الاستيراد والتصدير والنسخ
                                    والاستعادة.
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            disabled={jobsLoading}
                            aria-label="تحديث سجل العمليات"
                            onClick={() => void refreshJobs()}
                        >
                            <RefreshCw
                                aria-hidden="true"
                                className={jobsLoading ? 'is-spinning' : ''}
                            />
                        </button>
                    </header>
                    {jobsLoading && jobs.length === 0 ? (
                        <div
                            className="data-center-empty compact"
                            role="status"
                        >
                            <LoaderCircle
                                aria-hidden="true"
                                className="is-spinning"
                            />
                            <strong>جارٍ تحميل السجل…</strong>
                        </div>
                    ) : jobs.length === 0 ? (
                        <div className="data-center-empty compact">
                            <History aria-hidden="true" />
                            <strong>لا توجد عمليات مسجلة بعد</strong>
                        </div>
                    ) : (
                        <div className="data-jobs-list">
                            {jobs.slice(0, 12).map((job) => (
                                <article key={job.id}>
                                    <span
                                        className={`data-job-state state-${job.status}`}
                                    />
                                    <div>
                                        <strong>
                                            {jobLabels[job.type] ?? job.type}
                                            {job.format &&
                                            ['import', 'export'].includes(
                                                job.type,
                                            )
                                                ? ` ${job.format.toUpperCase()}`
                                                : ''}
                                        </strong>
                                        <small>
                                            {job.resource_type &&
                                            resourceLabels[
                                                job.resource_type as ExportResource
                                            ]
                                                ? resourceLabels[
                                                      job.resource_type as ExportResource
                                                  ]
                                                : (job.resource_type ??
                                                  'قاعدة البيانات')}
                                            {' · '}
                                            {formatDate(
                                                job.completed_at ??
                                                    job.created_at,
                                            )}
                                        </small>
                                    </div>
                                    <span>
                                        {statusLabels[job.status] ?? job.status}
                                    </span>
                                    {job.summary?.row_count !== undefined && (
                                        <bdi dir="ltr">
                                            {job.summary.row_count} صف
                                        </bdi>
                                    )}
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

DataCenterIndex.layout = {
    breadcrumbs: [{ title: 'مركز البيانات', href: '/data-center' }],
};

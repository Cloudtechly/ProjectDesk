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
import type { Dispatch, SetStateAction } from 'react';
import { createLocaleDateTimeFormatter } from '@/i18n/formatters';
import type {
    DataCenterTab,
    DataJob,
    ExportResource,
    ImportFormat,
    ImportResource,
} from './data-center-contracts';

const resourceLabels = {
    clients: 'العملاء',
    projects: 'المشاريع',
    tasks: 'المهام',
} as const;

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

type DataCenterWorkspaceProps = {
    tab: DataCenterTab;
    setTab: Dispatch<SetStateAction<DataCenterTab>>;
    resource: ImportResource;
    setResource: Dispatch<SetStateAction<ImportResource>>;
    importFormat: ImportFormat;
    setImportFormat: Dispatch<SetStateAction<ImportFormat>>;
    file: File | null;
    setFile: Dispatch<SetStateAction<File | null>>;
    importJob: DataJob | null;
    setImportJob: Dispatch<SetStateAction<DataJob | null>>;
    jobs: DataJob[];
    backupJobs: DataJob[];
    selectedBackup: DataJob | null;
    setSelectedBackup: Dispatch<SetStateAction<DataJob | null>>;
    restoreConfirmation: string;
    setRestoreConfirmation: Dispatch<SetStateAction<string>>;
    busy: string | null;
    notice: string;
    error: string;
    clearError: () => void;
    jobsLoading: boolean;
    refreshJobs: () => Promise<void>;
    previewImport: () => Promise<void>;
    commitImport: () => Promise<void>;
    createBackup: () => Promise<void>;
    uploadBackup: (upload: File) => Promise<void>;
    validateBackup: (job: DataJob) => Promise<void>;
    restoreBackup: () => Promise<void>;
};

export function DataCenterWorkspace(props: DataCenterWorkspaceProps) {
    const {
        tab,
        setTab,
        resource,
        setResource,
        importFormat,
        setImportFormat,
        file,
        setFile,
        importJob,
        setImportJob,
        jobs,
        backupJobs,
        selectedBackup,
        setSelectedBackup,
        restoreConfirmation,
        setRestoreConfirmation,
        busy,
        notice,
        error,
        clearError,
        jobsLoading,
        refreshJobs,
        previewImport,
        commitImport,
        createBackup,
        uploadBackup,
        validateBackup,
        restoreBackup,
    } = props;

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
                        <button type="button" onClick={clearError}>
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

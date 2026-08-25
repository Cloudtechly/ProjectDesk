import { useEffect, useMemo, useState } from 'react';
import type {
    BackupRestoreResult,
    BackupValidationResult,
    DataCenterTab,
    DataJob,
    ImportFormat,
    ImportResource,
} from '@/components/data-center/data-center-contracts';
import { DataCenterWorkspace } from '@/components/data-center/data-center-workspace';

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
        <DataCenterWorkspace
            tab={tab}
            setTab={setTab}
            resource={resource}
            setResource={setResource}
            importFormat={importFormat}
            setImportFormat={setImportFormat}
            file={file}
            setFile={setFile}
            importJob={importJob}
            setImportJob={setImportJob}
            jobs={jobs}
            backupJobs={backupJobs}
            selectedBackup={selectedBackup}
            setSelectedBackup={setSelectedBackup}
            restoreConfirmation={restoreConfirmation}
            setRestoreConfirmation={setRestoreConfirmation}
            busy={busy}
            notice={notice}
            error={error}
            clearError={() => setError('')}
            jobsLoading={jobsLoading}
            refreshJobs={refreshJobs}
            previewImport={previewImport}
            commitImport={commitImport}
            createBackup={createBackup}
            uploadBackup={uploadBackup}
            validateBackup={validateBackup}
            restoreBackup={restoreBackup}
        />
    );
}

DataCenterIndex.layout = {
    breadcrumbs: [{ title: 'مركز البيانات', href: '/data-center' }],
};

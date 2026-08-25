import {
    AlertTriangle,
    CheckCircle2,
    FileText,
    Paperclip,
    Plus,
    UploadCloud,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import {
    createLocaleDateTimeFormatter,
    createLocaleNumberFormatter,
} from '@/i18n/formatters';
import { projectApi } from './project-api';
import type {
    AttachmentTargetOption,
    AttachmentTargetType,
    Project,
    ProjectFile,
    RequirementBookData,
    RequirementBookVersion,
} from './project-show-types';
import { RequirementAnalysisPanel } from './requirement-analysis-panel';

const dateFormatter = createLocaleDateTimeFormatter({
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});
const numberFormatter = createLocaleNumberFormatter();

function formatDate(value?: string | null) {
    if (!value) {
        return 'غير محدد';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

function formatFileSize(value: number) {
    return value < 1024 * 1024
        ? `${Math.ceil(value / 1024)} KB`
        : `${(value / 1024 / 1024).toFixed(1)} MB`;
}

function attachmentTargetLabel(file: ProjectFile) {
    if (file.target.type === 'task') {
        return `مهمة: ${file.target.code ?? ''} — ${file.target.label}`;
    }

    if (file.target.type === 'requirement') {
        return `متطلب: ${file.target.code ?? ''} — ${file.target.label}`;
    }

    return 'المشروع';
}

export function ProjectDocumentsPanel({
    project,
    canManage,
    canUploadFile,
}: {
    project: Project;
    canManage: boolean;
    canUploadFile: boolean;
}) {
    const [documentLoading, setDocumentLoading] = useState(false);
    const [documentBusy, setDocumentBusy] = useState(false);
    const [documentError, setDocumentError] = useState('');
    const [documentNotice, setDocumentNotice] = useState('');
    const [projectFiles, setProjectFiles] = useState<ProjectFile[]>([]);
    const [attachmentTargetType, setAttachmentTargetType] =
        useState<AttachmentTargetType>('project');
    const [attachmentTargetId, setAttachmentTargetId] = useState('');
    const [attachmentTargetQuery, setAttachmentTargetQuery] = useState('');
    const [attachmentTargets, setAttachmentTargets] = useState<
        AttachmentTargetOption[]
    >([]);
    const [attachmentTargetsLoading, setAttachmentTargetsLoading] =
        useState(false);
    const [attachmentTargetError, setAttachmentTargetError] = useState('');
    const attachmentTargetReady =
        attachmentTargetType === 'project' || attachmentTargetId !== '';
    const activeProjectFiles = projectFiles.filter((file) => !file.archived_at);
    const archivedProjectFiles = projectFiles.filter((file) =>
        Boolean(file.archived_at),
    );
    const [requirementBook, setRequirementBook] =
        useState<RequirementBookData | null>(null);
    const loadDocuments = useCallback(async () => {
        setDocumentLoading(true);
        setDocumentError('');

        try {
            const [book, files] = await Promise.all([
                projectApi<RequirementBookData>(
                    `/projects/${project.id}/requirement-book`,
                ),
                projectApi<ProjectFile[]>(
                    `/projects/${project.id}/files?per_page=50&include_archived=1`,
                ),
            ]);
            setRequirementBook(book);
            setProjectFiles(files);
        } catch (requestError) {
            setDocumentError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر تحميل مستندات المشروع.',
            );
        } finally {
            setDocumentLoading(false);
        }
    }, [project.id]);

    useEffect(() => {
        let cancelled = false;
        void Promise.all([
            projectApi<RequirementBookData>(
                `/projects/${project.id}/requirement-book`,
            ),
            projectApi<ProjectFile[]>(
                `/projects/${project.id}/files?per_page=50&include_archived=1`,
            ),
        ])
            .then(([book, files]) => {
                if (!cancelled) {
                    setRequirementBook(book);
                    setProjectFiles(files);
                }
            })
            .catch((requestError: unknown) => {
                if (!cancelled) {
                    setDocumentError(
                        requestError instanceof Error
                            ? requestError.message
                            : 'تعذر تحميل مستندات المشروع.',
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setDocumentLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [project.id]);

    useEffect(() => {
        if (attachmentTargetType === 'project') {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setAttachmentTargetsLoading(true);
            setAttachmentTargetError('');
            const params = new URLSearchParams({
                type: attachmentTargetType,
            });

            if (attachmentTargetQuery.trim() !== '') {
                params.set('q', attachmentTargetQuery.trim());
            }

            void projectApi<AttachmentTargetOption[]>(
                `/projects/${project.id}/file-targets?${params.toString()}`,
                { signal: controller.signal },
            )
                .then((targets) => {
                    setAttachmentTargets(targets);
                    setAttachmentTargetId((current) =>
                        targets.some((target) => String(target.id) === current)
                            ? current
                            : '',
                    );
                })
                .catch((requestError: unknown) => {
                    if (
                        requestError instanceof DOMException &&
                        requestError.name === 'AbortError'
                    ) {
                        return;
                    }

                    setAttachmentTargets([]);
                    setAttachmentTargetId('');
                    setAttachmentTargetError(
                        requestError instanceof Error
                            ? requestError.message
                            : 'تعذر تحميل أهداف الربط.',
                    );
                })
                .finally(() => {
                    if (!controller.signal.aborted) {
                        setAttachmentTargetsLoading(false);
                    }
                });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [attachmentTargetQuery, attachmentTargetType, project.id]);

    async function uploadProjectFile(file: File) {
        if (!attachmentTargetReady) {
            setAttachmentTargetError('اختر مهمة أو متطلباً قبل رفع المرفق.');

            return;
        }

        setDocumentBusy(true);
        setDocumentError('');
        setDocumentNotice('');
        const body = new FormData();
        body.append('file', file);
        body.append('target_type', attachmentTargetType);

        if (attachmentTargetType !== 'project') {
            body.append('target_id', attachmentTargetId);
        }

        try {
            await projectApi<ProjectFile>(`/projects/${project.id}/files`, {
                method: 'POST',
                body,
            });
            setDocumentNotice('تم رفع المرفق وحفظه ضمن المشروع.');
            await loadDocuments();
        } catch (requestError) {
            setDocumentError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر رفع المرفق.',
            );
        } finally {
            setDocumentBusy(false);
        }
    }

    async function archiveProjectFile(file: ProjectFile) {
        if (
            !window.confirm(
                `أرشفة المرفق ${file.original_name} من هذا المشروع؟ سيبقى السجل محفوظاً.`,
            )
        ) {
            return;
        }

        setDocumentBusy(true);
        setDocumentError('');
        setDocumentNotice('');

        try {
            await projectApi(
                `/projects/${project.id}/files/${file.id}/links/${file.link_id}/archive`,
                { method: 'POST', body: JSON.stringify({}) },
            );
            setDocumentNotice('تمت أرشفة المرفق دون حذفه نهائياً.');
            await loadDocuments();
        } catch (requestError) {
            setDocumentError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذرت أرشفة المرفق.',
            );
        } finally {
            setDocumentBusy(false);
        }
    }

    async function restoreProjectFile(file: ProjectFile) {
        setDocumentBusy(true);
        setDocumentError('');
        setDocumentNotice('');

        try {
            await projectApi(
                `/projects/${project.id}/files/${file.id}/links/${file.link_id}/restore`,
                { method: 'POST', body: JSON.stringify({}) },
            );
            setDocumentNotice('تمت استعادة المرفق وأصبح متاحاً للتنزيل.');
            await loadDocuments();
        } catch (requestError) {
            setDocumentError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذرت استعادة المرفق.',
            );
        } finally {
            setDocumentBusy(false);
        }
    }

    async function uploadRequirementBook(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = event.currentTarget;
        setDocumentBusy(true);
        setDocumentError('');
        setDocumentNotice('');
        const body = new FormData(form);

        try {
            await projectApi<RequirementBookVersion>(
                `/projects/${project.id}/requirement-book/versions`,
                { method: 'POST', body },
            );
            form.reset();
            setDocumentNotice('تم رفع إصدار جديد من كراسة المتطلبات.');
            await loadDocuments();
        } catch (requestError) {
            setDocumentError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر رفع كراسة المتطلبات.',
            );
        } finally {
            setDocumentBusy(false);
        }
    }

    async function actOnRequirementBookVersion(
        version: RequirementBookVersion,
        action: 'make-current' | 'archive',
    ) {
        if (
            action === 'archive' &&
            !window.confirm(
                'ستؤرشف هذه النسخة دون حذف ملفها. هل تريد المتابعة؟',
            )
        ) {
            return;
        }

        setDocumentBusy(true);
        setDocumentError('');
        setDocumentNotice('');

        try {
            await projectApi<RequirementBookVersion>(
                `/projects/${project.id}/requirement-book/versions/${version.id}/${action}`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        lock_version: version.lock_version,
                    }),
                },
            );
            setDocumentNotice(
                action === 'make-current'
                    ? 'تم تعيين الإصدار الحالي للكراسة.'
                    : 'تمت أرشفة الإصدار دون حذف ملفه.',
            );
            await loadDocuments();
        } catch (requestError) {
            setDocumentError(
                requestError instanceof Error
                    ? requestError.message
                    : 'تعذر تحديث إصدار الكراسة.',
            );
        } finally {
            setDocumentBusy(false);
        }
    }

    return (
        <div className="project-documents-workspace">
            <div className="sr-only" aria-live="polite">
                {documentNotice || documentError}
            </div>
            {documentError && (
                <div className="cloudtech-alert danger" role="alert">
                    <AlertTriangle aria-hidden="true" />
                    <span>{documentError}</span>
                    <button type="button" onClick={() => void loadDocuments()}>
                        إعادة المحاولة
                    </button>
                </div>
            )}
            {documentNotice && (
                <div className="cloudtech-alert success" role="status">
                    <CheckCircle2 aria-hidden="true" />
                    <span>{documentNotice}</span>
                </div>
            )}

            <section className="project-requirement-book">
                <header>
                    <div>
                        <FileText aria-hidden="true" />
                        <div>
                            <h2 id="project-panel-title">كراسة المتطلبات</h2>
                            <p>
                                سجل إصدارات محفوظ مع نسخة حالية واضحة ودون حذف
                                الملفات السابقة.
                            </p>
                        </div>
                    </div>
                    <span>
                        {numberFormatter.format(
                            requirementBook?.versions.length ?? 0,
                        )}{' '}
                        إصدارات
                    </span>
                </header>

                {canManage && (
                    <form
                        className="requirement-book-upload"
                        onSubmit={(event) => void uploadRequirementBook(event)}
                    >
                        <label>
                            <span>عنوان الكراسة</span>
                            <input
                                name="title"
                                required
                                placeholder="كراسة متطلبات المشروع"
                            />
                        </label>
                        <label>
                            <span>الحالة</span>
                            <select name="status" defaultValue="draft">
                                <option value="draft">مسودة</option>
                                <option value="under_review">
                                    قيد المراجعة
                                </option>
                                <option value="approved">معتمدة</option>
                                <option value="superseded">مستبدلة</option>
                            </select>
                        </label>
                        <label className="requirement-book-file">
                            <UploadCloud aria-hidden="true" />
                            <span>اختر PDF أو Word أو Excel</span>
                            <input
                                name="file"
                                type="file"
                                required
                                accept=".pdf,.docx,.xlsx"
                            />
                        </label>
                        <label className="requirement-book-current">
                            <input
                                name="is_current"
                                type="checkbox"
                                value="1"
                                defaultChecked
                            />
                            تعيينه كإصدار حالي
                        </label>
                        <label className="requirement-book-note">
                            <span>ملاحظة الإصدار (اختيارية)</span>
                            <input
                                name="note"
                                placeholder="ما الذي تغير في هذا الإصدار؟"
                            />
                        </label>
                        <button
                            className="cloudtech-primary-action"
                            type="submit"
                            disabled={documentBusy}
                        >
                            <UploadCloud aria-hidden="true" />
                            {documentBusy
                                ? 'جارٍ الرفع والفحص…'
                                : 'رفع إصدار جديد'}
                        </button>
                    </form>
                )}

                {documentLoading ? (
                    <div className="project-documents-loading" role="status">
                        جارٍ تحميل كراسة المتطلبات…
                    </div>
                ) : (requirementBook?.versions.length ?? 0) === 0 ? (
                    <div className="project-documents-empty">
                        <FileText aria-hidden="true" />
                        <strong>لم تُرفع كراسة متطلبات بعد</strong>
                        <span>
                            يمكن إنشاء المشروع أولاً وإضافة الكراسة في أي وقت
                            لاحقاً.
                        </span>
                    </div>
                ) : (
                    <div className="requirement-book-versions">
                        {requirementBook?.versions.map((version) => (
                            <article key={version.id}>
                                <span
                                    className={`requirement-book-status status-${version.status}`}
                                >
                                    {version.status === 'approved'
                                        ? 'معتمدة'
                                        : version.status === 'under_review'
                                          ? 'قيد المراجعة'
                                          : version.status === 'superseded'
                                            ? 'مستبدلة'
                                            : 'مسودة'}
                                </span>
                                <div>
                                    <strong>
                                        {version.title ||
                                            requirementBook?.title ||
                                            'كراسة المتطلبات'}
                                        {version.is_current && (
                                            <em>الإصدار الحالي</em>
                                        )}
                                    </strong>
                                    <small>
                                        الإصدار{' '}
                                        {numberFormatter.format(
                                            version.version_number,
                                        )}{' '}
                                        · {version.uploader.name} ·{' '}
                                        {formatDate(version.uploaded_at)}
                                    </small>
                                    {version.note && <p>{version.note}</p>}
                                </div>
                                <div className="requirement-book-actions">
                                    {version.file.download_url && (
                                        <a href={version.file.download_url}>
                                            تنزيل الملف
                                        </a>
                                    )}
                                    {canManage && !version.is_current && (
                                        <button
                                            type="button"
                                            disabled={documentBusy}
                                            onClick={() =>
                                                void actOnRequirementBookVersion(
                                                    version,
                                                    'make-current',
                                                )
                                            }
                                        >
                                            تعيين كحالي
                                        </button>
                                    )}
                                    {canManage && (
                                        <button
                                            type="button"
                                            className="is-danger"
                                            disabled={documentBusy}
                                            onClick={() =>
                                                void actOnRequirementBookVersion(
                                                    version,
                                                    'archive',
                                                )
                                            }
                                        >
                                            أرشفة
                                        </button>
                                    )}
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </section>

            <RequirementAnalysisPanel
                projectId={project.id}
                versions={requirementBook?.versions ?? []}
                canManage={canManage}
            />

            <div className="project-document-grid">
                <section className="project-files-panel">
                    <header>
                        <div>
                            <Paperclip aria-hidden="true" />
                            <div>
                                <h2>مرفقات المشروع</h2>
                                <p>
                                    ملفات مرتبطة بالمشروع أو بمهامه أو متطلباته.
                                </p>
                            </div>
                        </div>
                    </header>
                    {canUploadFile && (
                        <div className="project-file-uploader">
                            <div className="project-file-target-fields">
                                <label>
                                    <span>ربط المرفق بـ</span>
                                    <select
                                        value={attachmentTargetType}
                                        disabled={documentBusy}
                                        onChange={(event) => {
                                            setAttachmentTargetType(
                                                event.currentTarget
                                                    .value as AttachmentTargetType,
                                            );
                                            setAttachmentTargetQuery('');
                                            setAttachmentTargetId('');
                                            setAttachmentTargets([]);
                                            setAttachmentTargetError('');
                                        }}
                                    >
                                        <option value="project">المشروع</option>
                                        <option value="task">مهمة</option>
                                        <option value="requirement">
                                            متطلب
                                        </option>
                                    </select>
                                </label>
                                {attachmentTargetType !== 'project' && (
                                    <>
                                        <label>
                                            <span>بحث</span>
                                            <input
                                                type="search"
                                                value={attachmentTargetQuery}
                                                disabled={documentBusy}
                                                placeholder="ابحث بالرمز أو العنوان"
                                                onChange={(event) =>
                                                    setAttachmentTargetQuery(
                                                        event.currentTarget
                                                            .value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <label>
                                            <span>
                                                {attachmentTargetType === 'task'
                                                    ? 'المهمة'
                                                    : 'المتطلب'}
                                            </span>
                                            <select
                                                value={attachmentTargetId}
                                                disabled={
                                                    documentBusy ||
                                                    attachmentTargetsLoading
                                                }
                                                onChange={(event) =>
                                                    setAttachmentTargetId(
                                                        event.currentTarget
                                                            .value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    {attachmentTargetsLoading
                                                        ? 'جارٍ التحميل…'
                                                        : 'اختر هدف الربط'}
                                                </option>
                                                {attachmentTargets.map(
                                                    (target) => (
                                                        <option
                                                            key={target.id}
                                                            value={target.id}
                                                        >
                                                            {target.code} —{' '}
                                                            {target.title}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </label>
                                    </>
                                )}
                            </div>
                            <label
                                className={`project-file-upload${
                                    attachmentTargetReady ? '' : 'is-disabled'
                                }`}
                            >
                                <Plus aria-hidden="true" />
                                <span>رفع مرفق</span>
                                <input
                                    type="file"
                                    accept=".pdf,.docx,.xlsx,.csv,.jpg,.jpeg,.png,.webp"
                                    disabled={
                                        documentBusy || !attachmentTargetReady
                                    }
                                    onChange={(event) => {
                                        const file =
                                            event.currentTarget.files?.[0];

                                        if (file) {
                                            void uploadProjectFile(file);
                                        }

                                        event.currentTarget.value = '';
                                    }}
                                />
                            </label>
                        </div>
                    )}
                    {attachmentTargetError && (
                        <p className="project-file-target-error" role="alert">
                            {attachmentTargetError}
                        </p>
                    )}
                    {activeProjectFiles.length === 0 ? (
                        <div className="project-documents-empty compact">
                            <Paperclip aria-hidden="true" />
                            <strong>لا توجد مرفقات مرتبطة</strong>
                        </div>
                    ) : (
                        <ul className="project-file-list">
                            {activeProjectFiles.map((file) => (
                                <li key={file.link_id}>
                                    <FileText aria-hidden="true" />
                                    <div>
                                        <strong>{file.original_name}</strong>
                                        <small>
                                            <span className="project-file-target-badge">
                                                {attachmentTargetLabel(file)}
                                            </span>{' '}
                                            · {file.uploader?.name || 'مستخدم'}{' '}
                                            · {formatFileSize(file.size_bytes)}
                                            {!file.download_url &&
                                                ' · قيد الفحص الأمني'}
                                        </small>
                                    </div>
                                    {file.download_url && (
                                        <a href={file.download_url}>تنزيل</a>
                                    )}
                                    {file.can_archive && (
                                        <button
                                            type="button"
                                            className="is-danger"
                                            disabled={documentBusy}
                                            onClick={() =>
                                                void archiveProjectFile(file)
                                            }
                                        >
                                            أرشفة
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {archivedProjectFiles.length > 0 && (
                        <details className="project-file-archive">
                            <summary>
                                المرفقات المؤرشفة ({archivedProjectFiles.length}
                                )
                            </summary>
                            <ul className="project-file-list">
                                {archivedProjectFiles.map((file) => (
                                    <li
                                        key={file.link_id}
                                        className="is-archived"
                                    >
                                        <FileText aria-hidden="true" />
                                        <div>
                                            <strong>
                                                {file.original_name}
                                            </strong>
                                            <small>
                                                <span className="project-file-target-badge">
                                                    {attachmentTargetLabel(
                                                        file,
                                                    )}
                                                </span>{' '}
                                                · مؤرشف · محفوظ في سجل المشروع
                                            </small>
                                        </div>
                                        {file.can_restore && (
                                            <button
                                                type="button"
                                                disabled={documentBusy}
                                                onClick={() =>
                                                    void restoreProjectFile(
                                                        file,
                                                    )
                                                }
                                            >
                                                استعادة
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                </section>
            </div>
        </div>
    );
}

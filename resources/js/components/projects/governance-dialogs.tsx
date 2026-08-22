import { Form } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarPlus,
    ClipboardList,
    FileSignature,
    Flag,
    Pencil,
    Plus,
    ShieldAlert,
} from 'lucide-react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useUnsavedDialog } from '@/hooks/use-unsaved-changes';

type Member = {
    id: number | string;
    name: string;
};

type Status = {
    id: number | string;
    label: string;
};

export type RequirementRecord = {
    id: number | string;
    code: string;
    title: string;
    description?: string | null;
    acceptance_criteria?: string | null;
    priority?: string;
    status_id?: number | string | null;
    owner_id?: number | string | null;
    lock_version: number;
};

type BaseProps = {
    projectId: number | string;
    projectName: string;
    members?: Member[];
};

type RiskRecord = {
    id: number | string;
    lock_version: number;
    title: string;
    description?: string | null;
    probability: number;
    impact: number;
    status?: string;
    owner_id?: number | string | null;
    mitigation?: string | null;
    due_at?: string | null;
};

type IssueRecord = {
    id: number | string;
    lock_version: number;
    title: string;
    description?: string | null;
    severity?: string;
    status?: string;
    owner_id?: number | string | null;
    due_at?: string | null;
    resolution?: string | null;
};

type TimelineRecord = {
    id: number | string;
    lock_version: number;
    kind: string;
    title: string;
    starts_at: string;
    ends_at?: string | null;
    status?: string;
    owner_id?: number | string | null;
    note?: string | null;
};

type MeetingRecord = {
    id: number | string;
    lock_version: number;
    title: string;
    starts_at: string;
    ends_at?: string | null;
    status?: string;
    organizer_id?: number | string | null;
    location?: string | null;
    meeting_url?: string | null;
    agenda?: string | null;
    note?: string | null;
    attendees?: Array<{
        id: number | string;
        pivot?: { attendance_status?: string };
    }>;
};

function toDateTimeInput(value?: string | null) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value.slice(0, 16);
    }

    const offset = date.getTimezoneOffset() * 60_000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function DialogAction({
    label,
    icon: Icon = Plus,
}: {
    label: string;
    icon?: typeof Plus;
}) {
    return (
        <DialogTrigger asChild>
            <button type="button" className="project-panel-action">
                <Icon aria-hidden="true" />
                {label}
            </button>
        </DialogTrigger>
    );
}

function MemberSelect({
    name,
    label,
    members = [],
    defaultValue = '',
}: {
    name: string;
    label: string;
    members?: Member[];
    defaultValue?: number | string;
}) {
    return (
        <label>
            <span>{label}</span>
            <select name={name} defaultValue={defaultValue}>
                <option value="">غير محدد</option>
                {members.map((member) => (
                    <option key={member.id} value={member.id}>
                        {member.name}
                    </option>
                ))}
            </select>
        </label>
    );
}

function SubmitButton({
    processing,
    label,
}: {
    processing: boolean;
    label: string;
}) {
    return (
        <button
            type="submit"
            className="cloudtech-primary-action"
            disabled={processing}
        >
            {processing ? 'جارٍ الحفظ…' : label}
        </button>
    );
}

function useGovernanceDialogGuard(subject: string) {
    return useUnsavedDialog(
        false,
        `لديك تغييرات غير محفوظة في ${subject}. هل تريد تجاهلها؟`,
    );
}

export function RequirementDialog({
    projectId,
    projectName,
    members,
    statuses = [],
    requirement,
}: BaseProps & { statuses?: Status[]; requirement?: RequirementRecord }) {
    const {
        allowNextNavigation,
        closeAfterSuccess,
        markDirty,
        onOpenChange,
        open,
    } = useGovernanceDialogGuard('المتطلب');
    const titleId = useId();
    const codeId = useId();
    const statusId = useId();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogAction
                label={requirement ? 'تعديل المتطلب' : 'إضافة متطلب'}
                icon={requirement ? Pencil : ClipboardList}
            />
            <DialogContent className="cloudtech-dialog" dir="rtl">
                <DialogHeader className="text-start">
                    <p className="cloudtech-eyebrow">{projectName}</p>
                    <DialogTitle>
                        {requirement ? 'تعديل المتطلب' : 'متطلب جديد'}
                    </DialogTitle>
                    <DialogDescription>
                        {requirement
                            ? 'حدّث نطاق المتطلب ومعايير قبوله. يحمي رقم النسخة عملك من الكتابة فوق تعديل أحدث.'
                            : 'سجّل الوصف ومعايير القبول كي يبقى نطاق التنفيذ قابلاً للتتبع.'}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={
                        requirement
                            ? `/projects/${projectId}/requirements/${requirement.id}`
                            : `/projects/${projectId}/requirements`
                    }
                    method="post"
                    className="cloudtech-form"
                    onChange={markDirty}
                    onBefore={allowNextNavigation}
                    onSuccess={closeAfterSuccess}
                >
                    {({ errors, processing }) => (
                        <>
                            {requirement && (
                                <>
                                    <input
                                        type="hidden"
                                        name="_method"
                                        value="put"
                                    />
                                    <input
                                        type="hidden"
                                        name="lock_version"
                                        value={requirement.lock_version}
                                    />
                                </>
                            )}
                            <div className="cloudtech-form-grid two-columns">
                                <label htmlFor={codeId}>
                                    <span>رمز المتطلب (اختياري)</span>
                                    <input
                                        id={codeId}
                                        name="code"
                                        dir="ltr"
                                        placeholder="REQ-001"
                                        defaultValue={requirement?.code || ''}
                                        aria-invalid={Boolean(errors.code)}
                                        aria-describedby={
                                            errors.code
                                                ? `${codeId}-error`
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        id={`${codeId}-error`}
                                        role="alert"
                                        message={errors.code}
                                    />
                                </label>
                                <label htmlFor={titleId}>
                                    <span>العنوان</span>
                                    <input
                                        id={titleId}
                                        name="title"
                                        required
                                        autoFocus
                                        defaultValue={requirement?.title || ''}
                                        aria-invalid={Boolean(errors.title)}
                                        aria-describedby={
                                            errors.title
                                                ? `${titleId}-error`
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        id={`${titleId}-error`}
                                        role="alert"
                                        message={errors.title}
                                    />
                                </label>
                                <label>
                                    <span>الأولوية</span>
                                    <select
                                        name="priority"
                                        defaultValue={
                                            requirement?.priority || 'medium'
                                        }
                                    >
                                        <option value="low">منخفضة</option>
                                        <option value="medium">متوسطة</option>
                                        <option value="high">عالية</option>
                                        <option value="critical">حرجة</option>
                                    </select>
                                </label>
                                <label htmlFor={statusId}>
                                    <span>الحالة</span>
                                    <select
                                        id={statusId}
                                        name="status_id"
                                        required
                                        defaultValue={
                                            requirement?.status_id || ''
                                        }
                                        aria-invalid={Boolean(errors.status_id)}
                                        aria-describedby={
                                            errors.status_id
                                                ? `${statusId}-error`
                                                : undefined
                                        }
                                    >
                                        <option value="" disabled>
                                            اختر الحالة
                                        </option>
                                        {statuses.map((status) => (
                                            <option
                                                key={status.id}
                                                value={status.id}
                                            >
                                                {status.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        id={`${statusId}-error`}
                                        role="alert"
                                        message={errors.status_id}
                                    />
                                </label>
                                <MemberSelect
                                    name="owner_id"
                                    label="مالك المتطلب"
                                    members={members}
                                    defaultValue={requirement?.owner_id || ''}
                                />
                            </div>
                            <label>
                                <span>الوصف</span>
                                <textarea
                                    name="description"
                                    rows={4}
                                    defaultValue={
                                        requirement?.description || ''
                                    }
                                />
                                <InputError
                                    role="alert"
                                    message={errors.description}
                                />
                            </label>
                            <label>
                                <span>معايير القبول</span>
                                <textarea
                                    name="acceptance_criteria"
                                    rows={4}
                                    placeholder="ما الشروط التي تثبت اكتمال المتطلب؟"
                                    defaultValue={
                                        requirement?.acceptance_criteria || ''
                                    }
                                />
                                <InputError
                                    role="alert"
                                    message={errors.acceptance_criteria}
                                />
                            </label>
                            <SubmitButton
                                processing={processing}
                                label={
                                    requirement
                                        ? 'حفظ التعديلات'
                                        : 'حفظ المتطلب'
                                }
                            />
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export function RiskDialog({
    projectId,
    projectName,
    members,
    risk,
}: BaseProps & { risk?: RiskRecord }) {
    const {
        allowNextNavigation,
        closeAfterSuccess,
        markDirty,
        onOpenChange,
        open,
    } = useGovernanceDialogGuard('المخاطرة');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogAction
                label={risk ? 'تعديل المخاطرة' : 'تسجيل مخاطرة'}
                icon={risk ? Pencil : ShieldAlert}
            />
            <DialogContent className="cloudtech-dialog" dir="rtl">
                <DialogHeader className="text-start">
                    <p className="cloudtech-eyebrow">{projectName}</p>
                    <DialogTitle>
                        {risk ? 'تعديل المخاطرة' : 'مخاطرة جديدة'}
                    </DialogTitle>
                    <DialogDescription>
                        قيّم الاحتمال والأثر وسجّل خطة الاستجابة.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={
                        risk
                            ? `/projects/${projectId}/risks/${risk.id}`
                            : `/projects/${projectId}/risks`
                    }
                    method="post"
                    className="cloudtech-form"
                    onChange={markDirty}
                    onBefore={allowNextNavigation}
                    onSuccess={closeAfterSuccess}
                >
                    {({ errors, processing }) => (
                        <>
                            {risk && (
                                <>
                                    <input
                                        type="hidden"
                                        name="_method"
                                        value="put"
                                    />
                                    <input
                                        type="hidden"
                                        name="lock_version"
                                        value={risk.lock_version}
                                    />
                                </>
                            )}
                            <InputError message={errors.lock_version} />
                            <label>
                                <span>العنوان</span>
                                <input
                                    name="title"
                                    required
                                    autoFocus
                                    defaultValue={risk?.title}
                                />
                                <InputError message={errors.title} />
                            </label>
                            <label>
                                <span>الوصف</span>
                                <textarea
                                    name="description"
                                    rows={3}
                                    defaultValue={risk?.description ?? ''}
                                />
                            </label>
                            <div className="cloudtech-form-grid two-columns">
                                <label>
                                    <span>الاحتمال (1–5)</span>
                                    <input
                                        name="probability"
                                        type="number"
                                        min="1"
                                        max="5"
                                        defaultValue={risk?.probability ?? 3}
                                        required
                                    />
                                </label>
                                <label>
                                    <span>الأثر (1–5)</span>
                                    <input
                                        name="impact"
                                        type="number"
                                        min="1"
                                        max="5"
                                        defaultValue={risk?.impact ?? 3}
                                        required
                                    />
                                </label>
                                <label>
                                    <span>الحالة</span>
                                    <select
                                        name="status"
                                        defaultValue={risk?.status ?? 'open'}
                                    >
                                        <option value="open">مفتوحة</option>
                                        <option value="monitoring">
                                            تحت المراقبة
                                        </option>
                                        <option value="mitigated">
                                            تم التخفيف
                                        </option>
                                        <option value="accepted">مقبولة</option>
                                        <option value="closed">مغلقة</option>
                                    </select>
                                </label>
                                <MemberSelect
                                    name="owner_id"
                                    label="المالك"
                                    members={members}
                                    defaultValue={risk?.owner_id ?? ''}
                                />
                                <label>
                                    <span>تاريخ المتابعة</span>
                                    <input
                                        name="due_at"
                                        type="datetime-local"
                                        dir="ltr"
                                        defaultValue={toDateTimeInput(
                                            risk?.due_at,
                                        )}
                                    />
                                </label>
                            </div>
                            <label>
                                <span>خطة الاستجابة</span>
                                <textarea
                                    name="mitigation"
                                    rows={4}
                                    defaultValue={risk?.mitigation ?? ''}
                                />
                            </label>
                            <SubmitButton
                                processing={processing}
                                label={risk ? 'حفظ التعديلات' : 'حفظ المخاطرة'}
                            />
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export function IssueDialog({
    projectId,
    projectName,
    members,
    issue,
}: BaseProps & { issue?: IssueRecord }) {
    const {
        allowNextNavigation,
        closeAfterSuccess,
        markDirty,
        onOpenChange,
        open,
    } = useGovernanceDialogGuard('المشكلة');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogAction
                label={issue ? 'تعديل المشكلة' : 'تسجيل مشكلة'}
                icon={issue ? Pencil : AlertTriangle}
            />
            <DialogContent className="cloudtech-dialog" dir="rtl">
                <DialogHeader className="text-start">
                    <p className="cloudtech-eyebrow">{projectName}</p>
                    <DialogTitle>
                        {issue ? 'تعديل المشكلة' : 'مشكلة جديدة'}
                    </DialogTitle>
                    <DialogDescription>
                        سجّل العائق ومالكه وموعد معالجته.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={
                        issue
                            ? `/projects/${projectId}/issues/${issue.id}`
                            : `/projects/${projectId}/issues`
                    }
                    method="post"
                    className="cloudtech-form"
                    onChange={markDirty}
                    onBefore={allowNextNavigation}
                    onSuccess={closeAfterSuccess}
                >
                    {({ errors, processing }) => (
                        <>
                            {issue && (
                                <>
                                    <input
                                        type="hidden"
                                        name="_method"
                                        value="put"
                                    />
                                    <input
                                        type="hidden"
                                        name="lock_version"
                                        value={issue.lock_version}
                                    />
                                </>
                            )}
                            <InputError message={errors.lock_version} />
                            <label>
                                <span>العنوان</span>
                                <input
                                    name="title"
                                    required
                                    autoFocus
                                    defaultValue={issue?.title}
                                />
                                <InputError message={errors.title} />
                            </label>
                            <label>
                                <span>الوصف</span>
                                <textarea
                                    name="description"
                                    rows={3}
                                    defaultValue={issue?.description ?? ''}
                                />
                            </label>
                            <div className="cloudtech-form-grid two-columns">
                                <label>
                                    <span>الشدة</span>
                                    <select
                                        name="severity"
                                        defaultValue={
                                            issue?.severity ?? 'medium'
                                        }
                                    >
                                        <option value="low">منخفضة</option>
                                        <option value="medium">متوسطة</option>
                                        <option value="high">عالية</option>
                                        <option value="critical">حرجة</option>
                                    </select>
                                </label>
                                <label>
                                    <span>الحالة</span>
                                    <select
                                        name="status"
                                        defaultValue={issue?.status ?? 'open'}
                                    >
                                        <option value="open">مفتوحة</option>
                                        <option value="in_progress">
                                            قيد المعالجة
                                        </option>
                                        <option value="resolved">محلولة</option>
                                        <option value="closed">مغلقة</option>
                                    </select>
                                </label>
                                <MemberSelect
                                    name="owner_id"
                                    label="المسؤول"
                                    members={members}
                                    defaultValue={issue?.owner_id ?? ''}
                                />
                                <label>
                                    <span>الموعد المستهدف</span>
                                    <input
                                        name="due_at"
                                        type="datetime-local"
                                        dir="ltr"
                                        defaultValue={toDateTimeInput(
                                            issue?.due_at,
                                        )}
                                    />
                                </label>
                            </div>
                            <label>
                                <span>الحل (مطلوب عند الإغلاق)</span>
                                <textarea
                                    name="resolution"
                                    rows={4}
                                    defaultValue={issue?.resolution ?? ''}
                                />
                            </label>
                            <SubmitButton
                                processing={processing}
                                label={issue ? 'حفظ التعديلات' : 'حفظ المشكلة'}
                            />
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export function TimelineDialog({
    projectId,
    projectName,
    members,
    entry,
}: BaseProps & { entry?: TimelineRecord }) {
    const {
        allowNextNavigation,
        closeAfterSuccess,
        markDirty,
        onOpenChange,
        open,
    } = useGovernanceDialogGuard('بند الجدول الزمني');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogAction
                label={entry ? 'تعديل البند' : 'إضافة موعد أو مرحلة'}
                icon={entry ? Pencil : Flag}
            />
            <DialogContent className="cloudtech-dialog" dir="rtl">
                <DialogHeader className="text-start">
                    <p className="cloudtech-eyebrow">{projectName}</p>
                    <DialogTitle>
                        {entry
                            ? 'تعديل بند الجدول الزمني'
                            : 'بند في الجدول الزمني'}
                    </DialogTitle>
                    <DialogDescription>
                        أضف مرحلة أو تسليماً أو مراجعة بتاريخ فعلي.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={
                        entry
                            ? `/projects/${projectId}/timeline-entries/${entry.id}`
                            : `/projects/${projectId}/timeline-entries`
                    }
                    method="post"
                    className="cloudtech-form"
                    onChange={markDirty}
                    onBefore={allowNextNavigation}
                    onSuccess={closeAfterSuccess}
                >
                    {({ errors, processing }) => (
                        <>
                            {entry && (
                                <>
                                    <input
                                        type="hidden"
                                        name="_method"
                                        value="put"
                                    />
                                    <input
                                        type="hidden"
                                        name="lock_version"
                                        value={entry.lock_version}
                                    />
                                </>
                            )}
                            <InputError message={errors.lock_version} />
                            <div className="cloudtech-form-grid two-columns">
                                <label>
                                    <span>النوع</span>
                                    <select
                                        name="kind"
                                        defaultValue={
                                            entry?.kind ?? 'milestone'
                                        }
                                    >
                                        <option value="milestone">مرحلة</option>
                                        <option value="phase">طور عمل</option>
                                        <option value="delivery">تسليم</option>
                                        <option value="review">مراجعة</option>
                                        <option value="deadline">
                                            موعد نهائي
                                        </option>
                                        <option value="event">حدث</option>
                                    </select>
                                </label>
                                <label>
                                    <span>العنوان</span>
                                    <input
                                        name="title"
                                        required
                                        autoFocus
                                        defaultValue={entry?.title}
                                    />
                                    <InputError message={errors.title} />
                                </label>
                                <label>
                                    <span>البداية</span>
                                    <input
                                        name="starts_at"
                                        type="datetime-local"
                                        dir="ltr"
                                        required
                                        defaultValue={toDateTimeInput(
                                            entry?.starts_at,
                                        )}
                                    />
                                    <InputError message={errors.starts_at} />
                                </label>
                                <label>
                                    <span>النهاية (اختيارية)</span>
                                    <input
                                        name="ends_at"
                                        type="datetime-local"
                                        dir="ltr"
                                        defaultValue={toDateTimeInput(
                                            entry?.ends_at,
                                        )}
                                    />
                                    <InputError message={errors.ends_at} />
                                </label>
                                <label>
                                    <span>الحالة</span>
                                    <select
                                        name="status"
                                        defaultValue={
                                            entry?.status ?? 'planned'
                                        }
                                    >
                                        <option value="planned">مخطط</option>
                                        <option value="in_progress">
                                            قيد التنفيذ
                                        </option>
                                        <option value="completed">مكتمل</option>
                                        <option value="cancelled">ملغى</option>
                                    </select>
                                </label>
                                <MemberSelect
                                    name="owner_id"
                                    label="المالك"
                                    members={members}
                                    defaultValue={entry?.owner_id ?? ''}
                                />
                            </div>
                            <label>
                                <span>ملاحظة</span>
                                <textarea
                                    name="note"
                                    rows={4}
                                    defaultValue={entry?.note ?? ''}
                                />
                            </label>
                            <SubmitButton
                                processing={processing}
                                label={
                                    entry ? 'حفظ التعديلات' : 'إضافة إلى الجدول'
                                }
                            />
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export function MeetingDialog({
    projectId,
    projectName,
    members = [],
    meeting,
}: BaseProps & { meeting?: MeetingRecord }) {
    const initialAttendeeIds =
        meeting?.attendees?.map((attendee) => attendee.id) ?? [];
    const [attendeeIds, setAttendeeIds] =
        useState<Array<number | string>>(initialAttendeeIds);
    const {
        allowNextNavigation,
        closeAfterSuccess,
        markDirty,
        onOpenChange,
        open,
    } = useGovernanceDialogGuard('الاجتماع');

    function changeOpen(nextOpen: boolean) {
        if (onOpenChange(nextOpen)) {
            setAttendeeIds(initialAttendeeIds);
        }
    }

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogAction
                label={meeting ? 'تعديل الاجتماع' : 'جدولة اجتماع'}
                icon={meeting ? Pencil : CalendarPlus}
            />
            <DialogContent className="cloudtech-dialog" dir="rtl">
                <DialogHeader className="text-start">
                    <p className="cloudtech-eyebrow">{projectName}</p>
                    <DialogTitle>
                        {meeting ? 'تعديل الاجتماع' : 'اجتماع جديد'}
                    </DialogTitle>
                    <DialogDescription>
                        سيظهر الاجتماع تلقائياً في الجدول الزمني والتخطيط
                        الأسبوعي.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={
                        meeting
                            ? `/projects/${projectId}/meetings/${meeting.id}`
                            : `/projects/${projectId}/meetings`
                    }
                    method="post"
                    className="cloudtech-form"
                    onChange={markDirty}
                    onBefore={allowNextNavigation}
                    onSuccess={() => {
                        closeAfterSuccess();
                        setAttendeeIds(initialAttendeeIds);
                    }}
                >
                    {({ errors, processing }) => (
                        <>
                            {meeting && (
                                <>
                                    <input
                                        type="hidden"
                                        name="_method"
                                        value="put"
                                    />
                                    <input
                                        type="hidden"
                                        name="lock_version"
                                        value={meeting.lock_version}
                                    />
                                </>
                            )}
                            <InputError message={errors.lock_version} />
                            <label>
                                <span>عنوان الاجتماع</span>
                                <input
                                    name="title"
                                    required
                                    autoFocus
                                    defaultValue={meeting?.title}
                                />
                                <InputError message={errors.title} />
                            </label>
                            <div className="cloudtech-form-grid two-columns">
                                <label>
                                    <span>البداية</span>
                                    <input
                                        name="starts_at"
                                        type="datetime-local"
                                        dir="ltr"
                                        required
                                        defaultValue={toDateTimeInput(
                                            meeting?.starts_at,
                                        )}
                                    />
                                    <InputError message={errors.starts_at} />
                                </label>
                                <label>
                                    <span>النهاية</span>
                                    <input
                                        name="ends_at"
                                        type="datetime-local"
                                        dir="ltr"
                                        required
                                        defaultValue={toDateTimeInput(
                                            meeting?.ends_at,
                                        )}
                                    />
                                    <InputError message={errors.ends_at} />
                                </label>
                                <label>
                                    <span>الحالة</span>
                                    <select
                                        name="status"
                                        defaultValue={
                                            meeting?.status ?? 'planned'
                                        }
                                    >
                                        <option value="planned">مخطط</option>
                                        <option value="in_progress">
                                            جارٍ
                                        </option>
                                        <option value="completed">مكتمل</option>
                                        <option value="cancelled">ملغى</option>
                                    </select>
                                </label>
                                <MemberSelect
                                    name="organizer_id"
                                    label="المنظم"
                                    members={members}
                                    defaultValue={meeting?.organizer_id ?? ''}
                                />
                                <label>
                                    <span>المكان</span>
                                    <input
                                        name="location"
                                        defaultValue={meeting?.location ?? ''}
                                    />
                                </label>
                                <label>
                                    <span>رابط الاجتماع</span>
                                    <input
                                        name="meeting_url"
                                        type="url"
                                        dir="ltr"
                                        defaultValue={
                                            meeting?.meeting_url ?? ''
                                        }
                                    />
                                </label>
                            </div>
                            <label>
                                <span>جدول الأعمال</span>
                                <textarea
                                    name="agenda"
                                    rows={4}
                                    defaultValue={meeting?.agenda ?? ''}
                                />
                            </label>
                            <fieldset className="project-attendees-picker">
                                <legend>الحضور</legend>
                                {attendeeIds.map((memberId, index) => (
                                    <span key={memberId} hidden>
                                        <input
                                            name={`attendees[${index}][user_id]`}
                                            value={memberId}
                                            readOnly
                                        />
                                        <input
                                            name={`attendees[${index}][attendance_status]`}
                                            value={
                                                meeting?.attendees?.find(
                                                    (attendee) =>
                                                        attendee.id ===
                                                        memberId,
                                                )?.pivot?.attendance_status ??
                                                'invited'
                                            }
                                            readOnly
                                        />
                                    </span>
                                ))}
                                {members.map((member) => (
                                    <label key={member.id}>
                                        <input
                                            type="checkbox"
                                            checked={attendeeIds.includes(
                                                member.id,
                                            )}
                                            onChange={(event) =>
                                                setAttendeeIds((current) =>
                                                    event.target.checked
                                                        ? [
                                                              ...current,
                                                              member.id,
                                                          ]
                                                        : current.filter(
                                                              (id) =>
                                                                  id !==
                                                                  member.id,
                                                          ),
                                                )
                                            }
                                        />
                                        <span>{member.name}</span>
                                    </label>
                                ))}
                            </fieldset>
                            <SubmitButton
                                processing={processing}
                                label={
                                    meeting ? 'حفظ التعديلات' : 'جدولة الاجتماع'
                                }
                            />
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export function MinutesDialog({
    projectId,
    meeting,
}: {
    projectId: number | string;
    meeting: {
        id: number | string;
        title: string;
        minutes?: {
            lock_version: number;
            summary?: string | null;
            decisions?: string | null;
            action_items?: string | null;
            file_object_id?: number | null;
        } | null;
    };
}) {
    const {
        allowNextNavigation,
        closeAfterSuccess,
        markDirty,
        onOpenChange,
        open,
    } = useGovernanceDialogGuard('محضر الاجتماع');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogAction
                label={meeting.minutes ? 'تعديل المحضر' : 'إضافة محضر'}
                icon={FileSignature}
            />
            <DialogContent className="cloudtech-dialog" dir="rtl">
                <DialogHeader className="text-start">
                    <p className="cloudtech-eyebrow">{meeting.title}</p>
                    <DialogTitle>محضر الاجتماع</DialogTitle>
                    <DialogDescription>
                        احفظ الملخص والقرارات والإجراءات المتفق عليها في سجل
                        واحد.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={`/projects/${projectId}/meetings/${meeting.id}/minutes`}
                    method="post"
                    className="cloudtech-form"
                    onChange={markDirty}
                    onBefore={allowNextNavigation}
                    onSuccess={closeAfterSuccess}
                >
                    {({ errors, processing }) => (
                        <>
                            <input type="hidden" name="_method" value="put" />
                            {meeting.minutes && (
                                <input
                                    type="hidden"
                                    name="lock_version"
                                    value={meeting.minutes.lock_version}
                                />
                            )}
                            <InputError message={errors.lock_version} />
                            <label>
                                <span>ملخص الاجتماع</span>
                                <textarea
                                    name="summary"
                                    rows={5}
                                    required
                                    autoFocus
                                    defaultValue={
                                        meeting.minutes?.summary || ''
                                    }
                                />
                                <InputError message={errors.summary} />
                            </label>
                            <label>
                                <span>القرارات</span>
                                <textarea
                                    name="decisions"
                                    rows={4}
                                    defaultValue={
                                        meeting.minutes?.decisions || ''
                                    }
                                />
                            </label>
                            <label>
                                <span>بنود العمل والمتابعة</span>
                                <textarea
                                    name="action_items"
                                    rows={4}
                                    defaultValue={
                                        meeting.minutes?.action_items || ''
                                    }
                                />
                            </label>
                            <label>
                                <span>ملف المحضر (اختياري)</span>
                                <input
                                    name="attachment"
                                    type="file"
                                    accept=".pdf,.docx,.xlsx,.csv,.jpg,.jpeg,.png,.webp"
                                />
                                <small>
                                    {meeting.minutes?.file_object_id
                                        ? 'يوجد ملف محفوظ؛ رفع ملف جديد يستبدل الارتباط الحالي.'
                                        : 'يمكن إرفاق نسخة موقعة أو جدول إجراءات.'}
                                </small>
                                <InputError message={errors.attachment} />
                            </label>
                            <SubmitButton
                                processing={processing}
                                label="حفظ المحضر"
                            />
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

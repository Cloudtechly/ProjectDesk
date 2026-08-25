import { Form } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import type { Project } from '@/components/projects/project-show-types';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useUnsavedDialog } from '@/hooks/use-unsaved-changes';

const priorityLabels: Record<string, string> = {
    low: 'منخفضة',
    medium: 'متوسطة',
    high: 'عالية',
    critical: 'حرجة',
};

function toDateInput(value?: string | null) {
    return value ? value.slice(0, 10) : '';
}

export function EditProjectDialog({
    project,
    statuses,
    clients,
    members,
}: {
    project: Project;
    statuses: Array<{ id: number | string; label: string }>;
    clients: Array<{
        id: number | string;
        name: string;
        contacts?: Array<{ id: number | string; name: string }>;
    }>;
    members: Array<{
        id: number | string;
        name: string;
        global_role?: string;
    }>;
}) {
    const initialClientId = String(
        project.client_id ||
            (typeof project.client === 'object'
                ? project.client?.id || ''
                : ''),
    );
    const initialContactId = String(project.primary_contact_id || '');
    const [clientId, setClientId] = useState(initialClientId);
    const [contactId, setContactId] = useState(initialContactId);
    const {
        allowNextNavigation,
        closeAfterSuccess,
        markDirty,
        onOpenChange,
        open,
    } = useUnsavedDialog(
        false,
        'لديك تغييرات غير محفوظة في المشروع. هل تريد تجاهلها؟',
    );
    const contacts =
        clients.find((client) => String(client.id) === clientId)?.contacts ??
        [];
    const selectedMemberRoles = new Map(
        (project.members ?? []).map((member) => [
            String(member.id),
            member.pivot?.project_role || 'member',
        ]),
    );
    const statusId =
        project.status_id ||
        (typeof project.status === 'object' ? project.status?.id : '');

    function changeOpen(nextOpen: boolean) {
        if (onOpenChange(nextOpen)) {
            setClientId(initialClientId);
            setContactId(initialContactId);
        }
    }

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>
                <button type="button" className="project-secondary-action">
                    <Pencil aria-hidden="true" />
                    تعديل المشروع
                </button>
            </DialogTrigger>
            <DialogContent className="cloudtech-dialog" dir="rtl">
                <DialogHeader className="text-start">
                    <p className="cloudtech-eyebrow">إدارة المشروع</p>
                    <DialogTitle>تعديل {project.name}</DialogTitle>
                    <DialogDescription>
                        حدّث البيانات والفريق والجدول دون التأثير على المهام
                        المسجلة.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={`/projects/${project.id}`}
                    method="put"
                    className="cloudtech-form"
                    onChange={markDirty}
                    onBefore={allowNextNavigation}
                    onSuccess={closeAfterSuccess}
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="lock_version"
                                value={project.lock_version ?? 1}
                            />
                            <div className="cloudtech-form-grid two-columns">
                                <label>
                                    <span>رمز المشروع</span>
                                    <input
                                        name="code"
                                        defaultValue={project.code || ''}
                                        required
                                        dir="ltr"
                                    />
                                    <InputError message={errors.code} />
                                </label>
                                <label>
                                    <span>اسم المشروع</span>
                                    <input
                                        name="name"
                                        defaultValue={project.name}
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </label>
                            </div>
                            <label>
                                <span>الوصف</span>
                                <textarea
                                    name="description"
                                    rows={3}
                                    defaultValue={project.description || ''}
                                />
                                <InputError message={errors.description} />
                            </label>
                            <div className="cloudtech-form-grid two-columns">
                                <label>
                                    <span>العميل</span>
                                    <select
                                        name="client_id"
                                        value={clientId}
                                        onChange={(event) => {
                                            setClientId(event.target.value);
                                            setContactId('');
                                        }}
                                    >
                                        <option value="">دون عميل</option>
                                        {clients.map((client) => (
                                            <option
                                                key={client.id}
                                                value={client.id}
                                            >
                                                {client.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.client_id} />
                                </label>
                                <label>
                                    <span>جهة الاتصال الأساسية</span>
                                    <select
                                        name="primary_contact_id"
                                        value={contactId}
                                        onChange={(event) =>
                                            setContactId(event.target.value)
                                        }
                                    >
                                        <option value="">دون جهة محددة</option>
                                        {contacts.map((contact) => (
                                            <option
                                                key={contact.id}
                                                value={contact.id}
                                            >
                                                {contact.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.primary_contact_id}
                                    />
                                </label>
                                <label>
                                    <span>مدير المشروع</span>
                                    <select
                                        name="manager_id"
                                        defaultValue={String(
                                            project.manager_id || '',
                                        )}
                                    >
                                        <option value="">غير محدد</option>
                                        {members
                                            .filter(
                                                (member) =>
                                                    member.global_role !==
                                                    'viewer',
                                            )
                                            .map((member) => (
                                                <option
                                                    key={member.id}
                                                    value={member.id}
                                                >
                                                    {member.name}
                                                </option>
                                            ))}
                                    </select>
                                    <InputError message={errors.manager_id} />
                                </label>
                                <label>
                                    <span>الحالة</span>
                                    <select
                                        name="status_id"
                                        defaultValue={String(statusId || '')}
                                        required
                                    >
                                        {statuses.map((status) => (
                                            <option
                                                key={status.id}
                                                value={status.id}
                                            >
                                                {status.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.status_id} />
                                </label>
                                <label>
                                    <span>الأولوية</span>
                                    <select
                                        name="priority"
                                        defaultValue={
                                            project.priority || 'medium'
                                        }
                                        required
                                    >
                                        {Object.entries(priorityLabels).map(
                                            ([value, label]) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                    <InputError message={errors.priority} />
                                </label>
                                <label>
                                    <span>تاريخ البداية</span>
                                    <input
                                        name="start_date"
                                        type="date"
                                        defaultValue={toDateInput(
                                            project.start_date ??
                                                project.startDate,
                                        )}
                                    />
                                    <InputError message={errors.start_date} />
                                </label>
                                <label>
                                    <span>تاريخ النهاية</span>
                                    <input
                                        name="end_date"
                                        type="date"
                                        defaultValue={toDateInput(
                                            project.end_date ?? project.endDate,
                                        )}
                                    />
                                    <InputError message={errors.end_date} />
                                </label>
                            </div>
                            <fieldset className="project-member-picker">
                                <legend>أعضاء المشروع</legend>
                                <div>
                                    {members.map((member, index) => (
                                        <label key={member.id}>
                                            <input
                                                type="hidden"
                                                name={`members[${index}][id]`}
                                                value={member.id}
                                            />
                                            <span>{member.name}</span>
                                            <select
                                                name={`members[${index}][role]`}
                                                defaultValue={
                                                    selectedMemberRoles.get(
                                                        String(member.id),
                                                    ) || ''
                                                }
                                                aria-label={`دور ${member.name}`}
                                            >
                                                <option value="">
                                                    غير مضاف
                                                </option>
                                                {member.global_role !==
                                                    'viewer' && (
                                                    <>
                                                        <option value="manager">
                                                            مدير
                                                        </option>
                                                        <option value="member">
                                                            عضو
                                                        </option>
                                                    </>
                                                )}
                                                <option value="viewer">
                                                    مشاهد
                                                </option>
                                            </select>
                                        </label>
                                    ))}
                                </div>
                                <InputError
                                    message={
                                        errors.members || errors.member_ids
                                    }
                                />
                            </fieldset>
                            <div className="project-wizard-actions">
                                <button
                                    type="submit"
                                    className="cloudtech-primary-action"
                                    disabled={processing}
                                >
                                    {processing
                                        ? 'جارٍ الحفظ…'
                                        : 'حفظ التعديلات'}
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

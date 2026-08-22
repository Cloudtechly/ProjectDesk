import { Form, Head, Link } from '@inertiajs/react';
import {
    Archive,
    BriefcaseBusiness,
    Mail,
    Pencil,
    Phone,
    Plus,
    RotateCcw,
    Search,
    UserRound,
} from 'lucide-react';
import InputError from '@/components/input-error';
import { PageEmptyState } from '@/components/page-empty-state';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useUnsavedDialog } from '@/hooks/use-unsaved-changes';

type Project = { id: number; name: string; pivot?: { project_role?: string } };
type TeamTask = {
    id: number;
    title: string;
    due_at?: string | null;
    project?: { id: number; name: string };
    status?: { label?: string; color?: string };
    href?: string;
};
type Member = {
    id: number;
    name: string;
    email: string;
    phone?: string | null;
    job_title?: string | null;
    global_role: string;
    status: string;
    archived_at?: string | null;
    active_projects_count?: number;
    open_tasks_count?: number;
    projects?: Project[];
    assigned_tasks?: TeamTask[];
    can_update?: boolean;
    can_archive?: boolean;
    can_restore?: boolean;
};

type TeamProps = {
    members?: Member[];
    filters?: { q?: string; status?: string };
    canManage?: boolean;
};

const roleLabels: Record<string, string> = {
    admin: 'مدير النظام',
    project_manager: 'مدير مشاريع',
    member: 'عضو فريق',
    viewer: 'مشاهد',
};

function MemberForm({
    member,
    onDirtyChange,
    onBeforeSubmit,
    onSuccess,
}: {
    member?: Member;
    onDirtyChange?: () => void;
    onBeforeSubmit?: () => void;
    onSuccess?: () => void;
}) {
    return (
        <Form
            action={member ? `/team/${member.id}` : '/team'}
            method={member ? 'put' : 'post'}
            className="cloudtech-form"
            onChange={onDirtyChange}
            onBefore={onBeforeSubmit}
            onSuccess={onSuccess}
        >
            {({ errors, processing }) => (
                <>
                    <div className="cloudtech-form-grid two-columns">
                        <label>
                            <span>الاسم</span>
                            <input
                                name="name"
                                required
                                autoFocus
                                defaultValue={member?.name}
                            />
                            <InputError message={errors.name} />
                        </label>
                        <label>
                            <span>البريد الإلكتروني</span>
                            <input
                                name="email"
                                type="email"
                                required
                                dir="ltr"
                                defaultValue={member?.email}
                            />
                            <InputError message={errors.email} />
                        </label>
                        <label>
                            <span>الهاتف</span>
                            <input
                                name="phone"
                                dir="ltr"
                                defaultValue={member?.phone || ''}
                            />
                            <InputError message={errors.phone} />
                        </label>
                        <label>
                            <span>المسمى الوظيفي</span>
                            <input
                                name="job_title"
                                defaultValue={member?.job_title || ''}
                            />
                            <InputError message={errors.job_title} />
                        </label>
                        <label>
                            <span>الدور العام</span>
                            <select
                                name="global_role"
                                defaultValue={member?.global_role || 'member'}
                                required
                            >
                                {Object.entries(roleLabels).map(
                                    ([value, label]) => (
                                        <option
                                            key={value}
                                            value={value}
                                            data-translate
                                        >
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                            <InputError message={errors.global_role} />
                        </label>
                        <label>
                            <span>الحالة</span>
                            <select
                                name="status"
                                defaultValue={member?.status || 'active'}
                                required
                            >
                                <option value="active">نشط</option>
                                <option value="inactive">غير نشط</option>
                            </select>
                            <InputError message={errors.status} />
                        </label>
                        <label>
                            <span>
                                {member
                                    ? 'كلمة مرور جديدة (اختيارية)'
                                    : 'كلمة المرور'}
                            </span>
                            <input
                                name="password"
                                type="password"
                                required={!member}
                                autoComplete="new-password"
                            />
                            <InputError message={errors.password} />
                        </label>
                        <label>
                            <span>تأكيد كلمة المرور</span>
                            <input
                                name="password_confirmation"
                                type="password"
                                required={!member}
                                autoComplete="new-password"
                            />
                        </label>
                    </div>
                    <button
                        className="cloudtech-primary-action"
                        type="submit"
                        disabled={processing}
                    >
                        {processing
                            ? 'جارٍ الحفظ…'
                            : member
                              ? 'حفظ التعديلات'
                              : 'إضافة العضو'}
                    </button>
                </>
            )}
        </Form>
    );
}

function MemberDialog({ member }: { member?: Member }) {
    const {
        allowNextNavigation,
        closeAfterSuccess,
        markDirty,
        onOpenChange,
        open,
    } = useUnsavedDialog(
        false,
        'لديك تغييرات غير محفوظة في بيانات العضو. هل تريد تجاهلها؟',
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogTrigger asChild>
                {member ? (
                    <button
                        className="team-icon-action"
                        type="button"
                        aria-label={`تعديل ${member.name}`}
                    >
                        <Pencil aria-hidden="true" />
                    </button>
                ) : (
                    <button className="cloudtech-primary-action" type="button">
                        <Plus aria-hidden="true" />
                        إضافة عضو
                    </button>
                )}
            </DialogTrigger>
            <DialogContent className="cloudtech-dialog" dir="rtl">
                <DialogHeader className="text-start">
                    <p className="cloudtech-eyebrow">
                        {member ? 'تعديل الحساب' : 'حساب داخلي جديد'}
                    </p>
                    <DialogTitle>
                        {member ? member.name : 'إضافة عضو للفريق'}
                    </DialogTitle>
                    <DialogDescription>
                        تُحفظ العلاقات وسجل العمل عند أرشفة الحساب، ولا يُحذف
                        العضو نهائياً.
                    </DialogDescription>
                </DialogHeader>
                <MemberForm
                    member={member}
                    onDirtyChange={markDirty}
                    onBeforeSubmit={allowNextNavigation}
                    onSuccess={closeAfterSuccess}
                />
            </DialogContent>
        </Dialog>
    );
}

export default function TeamIndex({
    members = [],
    filters,
    canManage = false,
}: TeamProps) {
    return (
        <>
            <Head title="الفريق" />
            <div className="cloudtech-page team-page">
                <header className="cloudtech-page-head">
                    <div>
                        <p className="cloudtech-eyebrow">الأشخاص والقدرة</p>
                        <h1 tabIndex={-1}>أعضاء الفريق</h1>
                        <p>
                            راجع المشاريع والمهام الحالية لكل عضو، وأدر الحسابات
                            دون فقد التاريخ.
                        </p>
                    </div>
                    {canManage && <MemberDialog />}
                </header>

                <form
                    className="cloudtech-filter-bar"
                    action="/team"
                    method="get"
                >
                    <label className="cloudtech-filter-search">
                        <span className="sr-only">ابحث في الفريق</span>
                        <Search aria-hidden="true" />
                        <input
                            name="q"
                            defaultValue={filters?.q}
                            placeholder="الاسم، البريد أو الوظيفة…"
                        />
                    </label>
                    <label>
                        <span className="sr-only">حالة الحساب</span>
                        <select
                            name="status"
                            defaultValue={filters?.status || ''}
                        >
                            <option value="">الحسابات النشطة</option>
                            <option value="archived">المؤرشفة</option>
                        </select>
                    </label>
                    <button type="submit">تطبيق الفلاتر</button>
                    {Object.values(filters ?? {}).some(Boolean) && (
                        <Link href="/team">مسح</Link>
                    )}
                </form>

                {members.length === 0 ? (
                    <PageEmptyState
                        eyebrow="الفريق"
                        title="لا يوجد أعضاء للعرض"
                        description="أضف أول عضو داخلي أو غيّر الفلاتر الحالية."
                        icon={UserRound}
                        embedded
                    />
                ) : (
                    <div className="team-grid">
                        {members.map((member) => (
                            <article className="team-card" key={member.id}>
                                <header>
                                    <div
                                        className="team-avatar"
                                        aria-hidden="true"
                                    >
                                        {member.name.trim().slice(0, 1)}
                                    </div>
                                    <div>
                                        <h2>{member.name}</h2>
                                        <p>
                                            {member.job_title ||
                                                roleLabels[
                                                    member.global_role
                                                ] ||
                                                'عضو فريق'}
                                        </p>
                                    </div>
                                    <span
                                        className={`team-state ${member.archived_at ? 'is-archived' : ''}`}
                                    >
                                        {member.archived_at
                                            ? 'مؤرشف'
                                            : member.status === 'active'
                                              ? 'نشط'
                                              : 'غير نشط'}
                                    </span>
                                </header>
                                <div className="team-contact-row">
                                    <a
                                        href={`mailto:${member.email}`}
                                        dir="ltr"
                                    >
                                        <Mail aria-hidden="true" />
                                        {member.email}
                                    </a>
                                    {member.phone && (
                                        <a
                                            href={`tel:${member.phone}`}
                                            dir="ltr"
                                        >
                                            <Phone aria-hidden="true" />
                                            {member.phone}
                                        </a>
                                    )}
                                </div>
                                <dl className="team-metrics">
                                    <div>
                                        <dt>المشاريع الحالية</dt>
                                        <dd>
                                            {member.active_projects_count ?? 0}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>المهام المفتوحة</dt>
                                        <dd>{member.open_tasks_count ?? 0}</dd>
                                    </div>
                                </dl>
                                <section>
                                    <h3>
                                        <BriefcaseBusiness aria-hidden="true" />
                                        المشاريع
                                    </h3>
                                    {member.projects?.length ? (
                                        <ul>
                                            {member.projects
                                                .slice(0, 3)
                                                .map((project) => (
                                                    <li key={project.id}>
                                                        <Link
                                                            href={`/projects/${project.id}`}
                                                        >
                                                            {project.name}
                                                        </Link>
                                                        <span>
                                                            {project.pivot
                                                                ?.project_role ===
                                                            'manager'
                                                                ? 'مدير'
                                                                : 'عضو'}
                                                        </span>
                                                    </li>
                                                ))}
                                        </ul>
                                    ) : (
                                        <p>لا توجد مشاريع نشطة.</p>
                                    )}
                                </section>
                                <section>
                                    <h3>المهام الحالية</h3>
                                    {member.assigned_tasks?.length ? (
                                        <ul>
                                            {member.assigned_tasks
                                                .slice(0, 3)
                                                .map((task) => (
                                                    <li key={task.id}>
                                                        <Link
                                                            href={
                                                                task.href ||
                                                                '/tasks'
                                                            }
                                                        >
                                                            {task.title}
                                                        </Link>
                                                        <span>
                                                            {task.project?.name}
                                                        </span>
                                                    </li>
                                                ))}
                                        </ul>
                                    ) : (
                                        <p>لا توجد مهام مفتوحة.</p>
                                    )}
                                </section>
                                {(member.can_update ||
                                    member.can_archive ||
                                    member.can_restore) && (
                                    <footer>
                                        {member.can_update && (
                                            <MemberDialog member={member} />
                                        )}
                                        {member.can_archive && (
                                            <Form
                                                action={`/team/${member.id}/archive`}
                                                method="post"
                                            >
                                                {({ processing }) => (
                                                    <button
                                                        type="submit"
                                                        disabled={processing}
                                                    >
                                                        <Archive aria-hidden="true" />
                                                        أرشفة
                                                    </button>
                                                )}
                                            </Form>
                                        )}
                                        {member.can_restore && (
                                            <Form
                                                action={`/team/${member.id}/restore`}
                                                method="post"
                                            >
                                                {({ processing }) => (
                                                    <button
                                                        type="submit"
                                                        disabled={processing}
                                                    >
                                                        <RotateCcw aria-hidden="true" />
                                                        استعادة
                                                    </button>
                                                )}
                                            </Form>
                                        )}
                                    </footer>
                                )}
                            </article>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

TeamIndex.layout = { breadcrumbs: [{ title: 'الفريق', href: '/team' }] };

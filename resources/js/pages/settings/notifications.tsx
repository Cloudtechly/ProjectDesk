import { Head, useForm } from '@inertiajs/react';
import { BellRing } from 'lucide-react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';

type NotificationPreferences = {
    enabled: boolean;
    overdue_tasks: boolean;
    upcoming_tasks: boolean;
    meetings: boolean;
    lead_hours: number;
};

type Props = {
    preferences: NotificationPreferences;
    systemPolicy: NotificationPreferences;
};

function ToggleField({
    id,
    checked,
    disabled,
    label,
    description,
    onChange,
}: {
    id: keyof Omit<NotificationPreferences, 'lead_hours'>;
    checked: boolean;
    disabled?: boolean;
    label: string;
    description: string;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="system-settings-toggle" htmlFor={id}>
            <span>
                <strong>{label}</strong>
                <small>{description}</small>
            </span>
            <input
                id={id}
                type="checkbox"
                role="switch"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.currentTarget.checked)}
            />
        </label>
    );
}

export default function NotificationSettings({
    preferences,
    systemPolicy,
}: Props) {
    const form = useForm<NotificationPreferences>(preferences);
    const categoriesDisabled = !systemPolicy.enabled || !form.data.enabled;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.patch('/settings/notifications', { preserveScroll: true });
    };

    return (
        <>
            <Head title="تفضيلات التنبيهات" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="تفضيلات التنبيهات"
                    description="اختر ما تريد متابعته في مركز التنبيهات ضمن سياسة النظام العامة."
                />

                {!systemPolicy.enabled && (
                    <div
                        className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                        role="status"
                    >
                        مركز التنبيهات معطّل حالياً من مسؤول النظام. ستُحفظ
                        اختياراتك وتُطبّق عند إعادة تشغيله.
                    </div>
                )}

                <form
                    className="space-y-6"
                    onSubmit={submit}
                    aria-label="تحديث تفضيلات التنبيهات الشخصية"
                >
                    <div className="system-settings-stack">
                        <ToggleField
                            id="enabled"
                            checked={form.data.enabled}
                            disabled={!systemPolicy.enabled}
                            label="تفعيل تنبيهاتي"
                            description="إظهار التنبيهات الجديدة في مركز حسابك"
                            onChange={(enabled) =>
                                form.setData('enabled', enabled)
                            }
                        />
                        <ToggleField
                            id="overdue_tasks"
                            checked={form.data.overdue_tasks}
                            disabled={
                                categoriesDisabled ||
                                !systemPolicy.overdue_tasks
                            }
                            label="المهام المتأخرة"
                            description={
                                systemPolicy.overdue_tasks
                                    ? 'تنبيه عندما تتجاوز مهمة مفتوحة موعدها النهائي'
                                    : 'هذه الفئة معطّلة حسب سياسة النظام'
                            }
                            onChange={(value) =>
                                form.setData('overdue_tasks', value)
                            }
                        />
                        <ToggleField
                            id="upcoming_tasks"
                            checked={form.data.upcoming_tasks}
                            disabled={
                                categoriesDisabled ||
                                !systemPolicy.upcoming_tasks
                            }
                            label="المهام القريبة"
                            description={
                                systemPolicy.upcoming_tasks
                                    ? 'تنبيه قبل حلول موعد المهمة'
                                    : 'هذه الفئة معطّلة حسب سياسة النظام'
                            }
                            onChange={(value) =>
                                form.setData('upcoming_tasks', value)
                            }
                        />
                        <ToggleField
                            id="meetings"
                            checked={form.data.meetings}
                            disabled={
                                categoriesDisabled || !systemPolicy.meetings
                            }
                            label="الاجتماعات القادمة"
                            description={
                                systemPolicy.meetings
                                    ? 'تنبيه قبل موعد الاجتماع المسجل في المشروع'
                                    : 'هذه الفئة معطّلة حسب سياسة النظام'
                            }
                            onChange={(value) =>
                                form.setData('meetings', value)
                            }
                        />
                        <label
                            className="system-settings-number-field"
                            htmlFor="lead_hours"
                        >
                            <span>
                                <strong>مهلة التذكير</strong>
                                <small>
                                    من ساعة إلى {systemPolicy.lead_hours} ساعة،
                                    وهو السقف الذي حدده مسؤول النظام
                                </small>
                            </span>
                            <input
                                id="lead_hours"
                                name="lead_hours"
                                type="number"
                                min="1"
                                max={systemPolicy.lead_hours}
                                dir="ltr"
                                value={form.data.lead_hours}
                                disabled={categoriesDisabled}
                                aria-invalid={Boolean(form.errors.lead_hours)}
                                aria-describedby={
                                    form.errors.lead_hours
                                        ? 'lead-hours-error'
                                        : undefined
                                }
                                onChange={(event) =>
                                    form.setData(
                                        'lead_hours',
                                        Number(event.currentTarget.value),
                                    )
                                }
                            />
                        </label>
                    </div>

                    <InputError
                        id="lead-hours-error"
                        role="alert"
                        message={form.errors.lead_hours}
                    />

                    <div className="flex items-center gap-3">
                        <Button
                            type="submit"
                            disabled={form.processing}
                            aria-busy={form.processing}
                        >
                            <BellRing aria-hidden="true" />
                            {form.processing ? 'جارٍ الحفظ…' : 'حفظ التفضيلات'}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

NotificationSettings.layout = {
    breadcrumbs: [
        {
            title: 'تفضيلات التنبيهات',
            href: '/settings/notifications',
        },
    ],
};

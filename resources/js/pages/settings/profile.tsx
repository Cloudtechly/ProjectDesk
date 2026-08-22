import { Form, Head, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="إعدادات الملف الشخصي" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="البيانات الأساسية"
                    description="حدّث اسمك وعنوان البريد المرتبط بحسابك."
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                    aria-label="تحديث الملف الشخصي"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">الاسم الكامل</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="اكتب الاسم الكامل"
                                    aria-invalid={Boolean(errors.name)}
                                    aria-describedby={
                                        errors.name ? 'name-error' : undefined
                                    }
                                />

                                <InputError
                                    id="name-error"
                                    role="alert"
                                    aria-live="assertive"
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">البريد الإلكتروني</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full text-left"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    inputMode="email"
                                    dir="ltr"
                                    placeholder="name@example.com"
                                    aria-invalid={Boolean(errors.email)}
                                    aria-describedby={
                                        errors.email ? 'email-error' : undefined
                                    }
                                />

                                <InputError
                                    id="email-error"
                                    role="alert"
                                    aria-live="assertive"
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            لم يتم التحقق من بريدك الإلكتروني
                                            بعد.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                type="button"
                                                className="font-medium text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                                            >
                                                أعد إرسال رسالة التحقق
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div
                                                className="mt-2 text-sm font-medium text-green-700"
                                                role="status"
                                                aria-live="polite"
                                            >
                                                أرسلنا رابط تحقق جديدًا إلى
                                                بريدك الإلكتروني.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="update-profile-button"
                                    aria-busy={processing}
                                >
                                    {processing
                                        ? 'جارٍ الحفظ…'
                                        : 'حفظ التغييرات'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'إعدادات الملف الشخصي',
            href: edit(),
        },
    ],
};

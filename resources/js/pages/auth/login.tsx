import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <div dir="rtl">
            <Head title="تسجيل الدخول" />

            <PasskeyVerify
                label="الدخول بمفتاح المرور"
                loadingLabel="جارٍ التحقق…"
                separator="أو تابع بالبريد الإلكتروني"
            />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">البريد الإلكتروني</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                    dir="ltr"
                                    inputMode="email"
                                    aria-invalid={Boolean(errors.email)}
                                    aria-describedby={
                                        errors.email ? 'email-error' : undefined
                                    }
                                />
                                <InputError
                                    id="email-error"
                                    message={errors.email}
                                    role="alert"
                                    aria-live="assertive"
                                />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">
                                        كلمة المرور
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="mr-auto text-sm"
                                        >
                                            نسيت كلمة المرور؟
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="current-password"
                                    placeholder="أدخل كلمة المرور"
                                    dir="ltr"
                                    aria-invalid={Boolean(errors.password)}
                                    aria-describedby={
                                        errors.password
                                            ? 'password-error'
                                            : undefined
                                    }
                                />
                                <InputError
                                    id="password-error"
                                    message={errors.password}
                                    role="alert"
                                    aria-live="assertive"
                                />
                            </div>

                            <div className="flex items-center gap-3">
                                <Checkbox id="remember" name="remember" />
                                <Label htmlFor="remember">تذكرني</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 min-h-11 w-full"
                                disabled={processing}
                                aria-busy={processing}
                                data-test="login-button"
                            >
                                {processing && (
                                    <Spinner aria-label="جارٍ تسجيل الدخول" />
                                )}
                                {processing
                                    ? 'جارٍ تسجيل الدخول…'
                                    : 'تسجيل الدخول'}
                            </Button>
                        </div>

                        <p className="text-center text-sm text-muted-foreground">
                            ينشئ مدير النظام حسابات المستخدمين الداخليين فقط.
                        </p>
                    </>
                )}
            </Form>

            {status && (
                <div
                    className="mb-4 text-center text-sm font-medium text-green-700"
                    role="status"
                    aria-live="polite"
                >
                    {status}
                </div>
            )}
        </div>
    );
}

Login.layout = {
    title: 'تسجيل الدخول إلى حسابك',
    description:
        'أدخل بريدك الإلكتروني وكلمة المرور للمتابعة إلى لوحة المتابعة.',
};

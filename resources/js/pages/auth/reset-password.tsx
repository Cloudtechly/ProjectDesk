import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({ token, email, passwordRules }: Props) {
    return (
        <div dir="rtl">
            <Head title="إعادة تعيين كلمة المرور" />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">البريد الإلكتروني</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                className="mt-1 block w-full"
                                readOnly
                                dir="ltr"
                                aria-invalid={Boolean(errors.email)}
                                aria-describedby={
                                    errors.email ? 'email-error' : undefined
                                }
                            />
                            <InputError
                                id="email-error"
                                message={errors.email}
                                className="mt-2"
                                role="alert"
                                aria-live="assertive"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">
                                كلمة المرور الجديدة
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                autoFocus
                                placeholder="أدخل كلمة المرور الجديدة"
                                passwordrules={passwordRules}
                                required
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

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                تأكيد كلمة المرور
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                placeholder="أعد إدخال كلمة المرور الجديدة"
                                passwordrules={passwordRules}
                                required
                                dir="ltr"
                                aria-invalid={Boolean(
                                    errors.password_confirmation,
                                )}
                                aria-describedby={
                                    errors.password_confirmation
                                        ? 'password-confirmation-error'
                                        : undefined
                                }
                            />
                            <InputError
                                id="password-confirmation-error"
                                message={errors.password_confirmation}
                                className="mt-2"
                                role="alert"
                                aria-live="assertive"
                            />
                        </div>

                        <Button
                            type="submit"
                            className="mt-4 min-h-11 w-full"
                            disabled={processing}
                            aria-busy={processing}
                            data-test="reset-password-button"
                        >
                            {processing && (
                                <Spinner aria-label="جارٍ إعادة تعيين كلمة المرور" />
                            )}
                            {processing
                                ? 'جارٍ إعادة التعيين…'
                                : 'إعادة تعيين كلمة المرور'}
                        </Button>
                    </div>
                )}
            </Form>
        </div>
    );
}

ResetPassword.layout = {
    title: 'إعادة تعيين كلمة المرور',
    description: 'أنشئ كلمة مرور جديدة وآمنة لحسابك.',
};

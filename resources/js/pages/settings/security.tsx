import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const passwordConfirmationInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="إعدادات الأمان" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="تحديث كلمة المرور"
                    description="استخدم كلمة مرور طويلة وفريدة لحماية حسابك."
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();

                            return;
                        }

                        if (errors.password) {
                            passwordInput.current?.focus();

                            return;
                        }

                        if (errors.password_confirmation) {
                            passwordConfirmationInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                    aria-label="تحديث كلمة المرور"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">
                                    كلمة المرور الحالية
                                </Label>

                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    className="mt-1 block w-full"
                                    autoComplete="current-password"
                                    placeholder="أدخل كلمة المرور الحالية"
                                    dir="ltr"
                                    required
                                    aria-invalid={Boolean(
                                        errors.current_password,
                                    )}
                                    aria-describedby={
                                        errors.current_password
                                            ? 'current-password-error'
                                            : undefined
                                    }
                                />

                                <InputError
                                    id="current-password-error"
                                    role="alert"
                                    aria-live="assertive"
                                    message={errors.current_password}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    كلمة المرور الجديدة
                                </Label>

                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="أدخل كلمة المرور الجديدة"
                                    passwordrules={props.passwordRules}
                                    dir="ltr"
                                    required
                                    aria-invalid={Boolean(errors.password)}
                                    aria-describedby={
                                        errors.password
                                            ? 'password-help password-error'
                                            : 'password-help'
                                    }
                                />

                                <p
                                    id="password-help"
                                    className="text-sm text-muted-foreground"
                                >
                                    اختر عبارة مرور طويلة لا تستخدمها في أي حساب
                                    آخر.
                                </p>

                                <InputError
                                    id="password-error"
                                    role="alert"
                                    aria-live="assertive"
                                    message={errors.password}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    تأكيد كلمة المرور الجديدة
                                </Label>

                                <PasswordInput
                                    id="password_confirmation"
                                    ref={passwordConfirmationInput}
                                    name="password_confirmation"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="أعد إدخال كلمة المرور الجديدة"
                                    passwordrules={props.passwordRules}
                                    dir="ltr"
                                    required
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
                                    role="alert"
                                    aria-live="assertive"
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="update-password-button"
                                    aria-busy={processing}
                                >
                                    {processing
                                        ? 'جارٍ الحفظ…'
                                        : 'حفظ كلمة المرور'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'إعدادات الأمان',
            href: edit(),
        },
    ],
};

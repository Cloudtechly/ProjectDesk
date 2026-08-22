import { Form, Head } from '@inertiajs/react';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    return (
        <div dir="rtl">
            <Head title="تأكيد كلمة المرور" />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label="التأكيد بمفتاح المرور"
                loadingLabel="جارٍ التأكيد…"
                separator="أو أكّد بكلمة المرور"
            />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">كلمة المرور</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder="أدخل كلمة المرور"
                                autoComplete="current-password"
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

                        <div className="flex items-center">
                            <Button
                                type="submit"
                                className="min-h-11 w-full"
                                disabled={processing}
                                aria-busy={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && (
                                    <Spinner aria-label="جارٍ تأكيد كلمة المرور" />
                                )}
                                {processing
                                    ? 'جارٍ التأكيد…'
                                    : 'تأكيد كلمة المرور'}
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </div>
    );
}

ConfirmPassword.layout = {
    title: 'تأكيد كلمة المرور',
    description: 'هذه منطقة آمنة. أكّد كلمة المرور قبل متابعة الإجراء الحساس.',
};

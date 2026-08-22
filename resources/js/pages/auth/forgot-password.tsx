// Components
import { Form, Head } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <div dir="rtl">
            <Head title="استعادة كلمة المرور" />

            {status && (
                <div
                    className="mb-4 text-center text-sm font-medium text-green-700"
                    role="status"
                    aria-live="polite"
                >
                    {status}
                </div>
            )}

            <div className="space-y-6">
                <Form {...email.form()}>
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">البريد الإلكتروني</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoComplete="email"
                                    autoFocus
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

                            <div className="my-6 flex items-center justify-start">
                                <Button
                                    type="submit"
                                    className="min-h-11 w-full"
                                    disabled={processing}
                                    aria-busy={processing}
                                    data-test="email-password-reset-link-button"
                                >
                                    {processing && (
                                        <LoaderCircle
                                            className="h-4 w-4 animate-spin"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {processing
                                        ? 'جارٍ إرسال الرابط…'
                                        : 'إرسال رابط استعادة كلمة المرور'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="flex items-center justify-center gap-1 text-center text-sm text-muted-foreground">
                    <span>أو ارجع إلى</span>
                    <TextLink href={login()}>تسجيل الدخول</TextLink>
                </div>
            </div>
        </div>
    );
}

ForgotPassword.layout = {
    title: 'نسيت كلمة المرور؟',
    description:
        'أدخل بريدك الإلكتروني وسنرسل إليك رابطاً لاستعادة كلمة المرور.',
};

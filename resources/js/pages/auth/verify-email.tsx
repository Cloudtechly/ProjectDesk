// Components
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <div dir="rtl">
            <Head title="التحقق من البريد الإلكتروني" />

            {status === 'verification-link-sent' && (
                <div
                    className="mb-4 text-center text-sm font-medium text-green-700"
                    role="status"
                    aria-live="polite"
                >
                    أرسلنا رابط تحقق جديداً إلى بريدك الإلكتروني.
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button
                            type="submit"
                            className="min-h-11"
                            disabled={processing}
                            aria-busy={processing}
                            variant="secondary"
                        >
                            {processing && (
                                <Spinner aria-label="جارٍ إرسال رابط التحقق" />
                            )}
                            {processing
                                ? 'جارٍ إرسال الرابط…'
                                : 'إعادة إرسال رابط التحقق'}
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            تسجيل الخروج
                        </TextLink>
                    </>
                )}
            </Form>
        </div>
    );
}

VerifyEmail.layout = {
    title: 'التحقق من البريد الإلكتروني',
    description:
        'تحقق من بريدك الإلكتروني بالضغط على الرابط الذي أرسلناه إليك.',
};

import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { store } from '@/routes/two-factor/login';

export default function TwoFactorChallenge() {
    const [showRecoveryInput, setShowRecoveryInput] = useState<boolean>(false);
    const [code, setCode] = useState<string>('');

    const authConfigContent = useMemo<{
        title: string;
        description: string;
        toggleText: string;
    }>(() => {
        if (showRecoveryInput) {
            return {
                title: 'رمز الاسترداد',
                description:
                    'أكّد الوصول إلى حسابك بإدخال أحد رموز الاسترداد الاحتياطية.',
                toggleText: 'استخدام رمز تطبيق المصادقة',
            };
        }

        return {
            title: 'رمز المصادقة',
            description: 'أدخل الرمز الذي يعرضه تطبيق المصادقة المرتبط بحسابك.',
            toggleText: 'استخدام رمز استرداد',
        };
    }, [showRecoveryInput]);

    setLayoutProps({
        title: authConfigContent.title,
        description: authConfigContent.description,
    });

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
        setCode('');
    };

    return (
        <div dir="rtl">
            <Head title="المصادقة الثنائية" />

            <div className="space-y-6">
                <Form
                    {...store.form()}
                    className="space-y-4"
                    resetOnError
                    resetOnSuccess={!showRecoveryInput}
                >
                    {({ errors, processing, clearErrors }) => (
                        <>
                            {showRecoveryInput ? (
                                <div
                                    id="two-factor-input"
                                    className="grid gap-2"
                                >
                                    <Label htmlFor="recovery_code">
                                        رمز الاسترداد
                                    </Label>
                                    <Input
                                        id="recovery_code"
                                        name="recovery_code"
                                        type="text"
                                        placeholder="أدخل رمز الاسترداد"
                                        autoComplete="one-time-code"
                                        autoFocus
                                        required
                                        dir="ltr"
                                        spellCheck={false}
                                        aria-invalid={Boolean(
                                            errors.recovery_code,
                                        )}
                                        aria-describedby={
                                            errors.recovery_code
                                                ? 'recovery-code-error'
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        id="recovery-code-error"
                                        message={errors.recovery_code}
                                        role="alert"
                                        aria-live="assertive"
                                    />
                                </div>
                            ) : (
                                <div
                                    id="two-factor-input"
                                    className="flex flex-col items-center justify-center space-y-3 text-center"
                                >
                                    <Label htmlFor="code">رمز المصادقة</Label>
                                    <div className="flex w-full items-center justify-center">
                                        <InputOTP
                                            id="code"
                                            name="code"
                                            maxLength={OTP_MAX_LENGTH}
                                            value={code}
                                            onChange={(value) => setCode(value)}
                                            disabled={processing}
                                            pattern={REGEXP_ONLY_DIGITS}
                                            autoComplete="one-time-code"
                                            inputMode="numeric"
                                            autoFocus
                                            dir="ltr"
                                            aria-invalid={Boolean(errors.code)}
                                            aria-describedby={
                                                errors.code
                                                    ? 'code-error'
                                                    : undefined
                                            }
                                        >
                                            <InputOTPGroup>
                                                {Array.from(
                                                    { length: OTP_MAX_LENGTH },
                                                    (_, index) => (
                                                        <InputOTPSlot
                                                            key={index}
                                                            index={index}
                                                        />
                                                    ),
                                                )}
                                            </InputOTPGroup>
                                        </InputOTP>
                                    </div>
                                    <InputError
                                        id="code-error"
                                        message={errors.code}
                                        role="alert"
                                        aria-live="assertive"
                                    />
                                </div>
                            )}

                            <Button
                                type="submit"
                                className="min-h-11 w-full"
                                disabled={processing}
                                aria-busy={processing}
                            >
                                {processing ? 'جارٍ التحقق…' : 'متابعة'}
                            </Button>

                            <div className="text-center text-sm text-muted-foreground">
                                <span>أو يمكنك</span>{' '}
                                <button
                                    type="button"
                                    className="inline-flex min-h-11 cursor-pointer items-center text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                                    onClick={() =>
                                        toggleRecoveryMode(clearErrors)
                                    }
                                    aria-controls="two-factor-input"
                                >
                                    {authConfigContent.toggleText}
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </div>
    );
}

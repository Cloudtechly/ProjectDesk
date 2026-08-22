import { usePasskeyRegister } from '@laravel/passkeys/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    onSuccess: () => void;
};

export default function PasskeyRegistration({ onSuccess }: Props) {
    const [name, setName] = useState(() => {
        const ua = navigator.userAgent;

        const browser = [
            { pattern: /Edg|Edge/, name: 'Edge' },
            { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
            { pattern: /Firefox|FxiOS/, name: 'Firefox' },
            { pattern: /Chrome|CriOS/, name: 'Chrome' },
            { pattern: /Safari/, name: 'Safari' },
        ].find(({ pattern }) => pattern.test(ua))?.name;

        const os = [
            { pattern: /iPhone/, name: 'iPhone' },
            { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
            { pattern: /Android/, name: 'Android' },
            { pattern: /Mac/, name: 'Mac' },
            { pattern: /Windows/, name: 'Windows' },
        ].find(({ pattern }) => pattern.test(ua))?.name;

        return [browser, os].filter(Boolean).join(' على ') || '';
    });

    const [showForm, setShowForm] = useState(false);
    const { register, isLoading, error, isSupported } = usePasskeyRegister({
        onSuccess: () => {
            setName('');
            setShowForm(false);
            onSuccess();
        },
    });

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!name.trim()) {
            return;
        }

        await register(name);
    };

    const handleCancel = () => {
        setShowForm(false);
        setName('');
    };

    if (!isSupported) {
        return (
            <div className="text-sm text-muted-foreground" role="status">
                هذا المتصفح لا يدعم مفاتيح المرور.
            </div>
        );
    }

    if (!showForm) {
        return (
            <Button
                type="button"
                variant="outline"
                onClick={() => setShowForm(true)}
            >
                إضافة مفتاح مرور
            </Button>
        );
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="space-y-4 rounded-lg border border-border bg-muted/50 p-4"
            aria-label="تسجيل مفتاح مرور جديد"
        >
            <div className="grid gap-2">
                <Label htmlFor="passkey-name">اسم مفتاح المرور</Label>
                <Input
                    id="passkey-name"
                    type="text"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="مثال: حاسوب المكتب أو الهاتف"
                    className="mt-1 block w-full border-foreground/20"
                    autoComplete="off"
                    required
                    autoFocus
                    aria-invalid={Boolean(error)}
                    aria-describedby={
                        error
                            ? 'passkey-name-help passkey-error'
                            : 'passkey-name-help'
                    }
                />
                <p
                    id="passkey-name-help"
                    className="text-xs text-muted-foreground"
                >
                    اختر اسمًا يسهّل تمييز هذا المفتاح لاحقًا.
                </p>
            </div>

            {error && (
                <InputError
                    id="passkey-error"
                    role="alert"
                    aria-live="assertive"
                    message={error}
                />
            )}

            <div className="flex gap-2">
                <Button type="submit" disabled={isLoading || !name.trim()}>
                    {isLoading ? 'جارٍ التسجيل…' : 'تسجيل مفتاح المرور'}
                </Button>
                <Button type="button" variant="ghost" onClick={handleCancel}>
                    إلغاء
                </Button>
            </div>
        </form>
    );
}

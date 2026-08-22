import { Form, Link } from '@inertiajs/react';
import { ArrowRight, Save } from 'lucide-react';
import InputError from '@/components/input-error';

export type ClientRecord = {
    id: number | string;
    code: string;
    name: string;
    email?: string | null;
    phone?: string | null;
    address?: string | null;
    status?: 'active' | 'inactive' | 'archived' | string;
    archived_at?: string | null;
};

export function ClientForm({ client }: { client?: ClientRecord | null }) {
    const editing = Boolean(client);

    return (
        <Form
            action={editing ? `/clients/${client?.id}` : '/clients'}
            method={editing ? 'put' : 'post'}
            className="cloudtech-form client-form"
        >
            {({ errors, processing }) => (
                <>
                    <div className="cloudtech-form-grid two-columns">
                        <label>
                            <span>رمز العميل</span>
                            <input
                                name="code"
                                required
                                dir="ltr"
                                placeholder="CLI-001"
                                defaultValue={client?.code}
                                autoFocus
                            />
                            <InputError message={errors.code} />
                        </label>
                        <label>
                            <span>اسم العميل</span>
                            <input
                                name="name"
                                required
                                placeholder="اسم الشركة أو الجهة"
                                defaultValue={client?.name}
                            />
                            <InputError message={errors.name} />
                        </label>
                        <label>
                            <span>البريد الإلكتروني</span>
                            <input
                                name="email"
                                type="email"
                                dir="ltr"
                                placeholder="contact@example.com"
                                defaultValue={client?.email || ''}
                            />
                            <InputError message={errors.email} />
                        </label>
                        <label>
                            <span>الهاتف</span>
                            <input
                                name="phone"
                                type="tel"
                                dir="ltr"
                                placeholder="+218 …"
                                defaultValue={client?.phone || ''}
                            />
                            <InputError message={errors.phone} />
                        </label>
                    </div>
                    <label>
                        <span>العنوان البريدي</span>
                        <textarea
                            name="address"
                            rows={3}
                            placeholder="المدينة، الشارع، وأي تفاصيل مساعدة"
                            defaultValue={client?.address || ''}
                        />
                        <InputError message={errors.address} />
                    </label>
                    <label>
                        <span>الحالة</span>
                        <select
                            name="status"
                            required
                            defaultValue={
                                client?.status === 'inactive'
                                    ? 'inactive'
                                    : 'active'
                            }
                        >
                            <option value="active">نشط</option>
                            <option value="inactive">غير نشط</option>
                        </select>
                        <InputError message={errors.status} />
                    </label>

                    <div className="cloudtech-form-actions">
                        <Link
                            href={client ? `/clients/${client.id}` : '/clients'}
                        >
                            <ArrowRight aria-hidden="true" />
                            إلغاء
                        </Link>
                        <button
                            className="cloudtech-primary-action"
                            type="submit"
                            disabled={processing}
                        >
                            <Save aria-hidden="true" />
                            {processing
                                ? 'جارٍ الحفظ…'
                                : editing
                                  ? 'حفظ التعديلات'
                                  : 'حفظ العميل'}
                        </button>
                    </div>
                </>
            )}
        </Form>
    );
}

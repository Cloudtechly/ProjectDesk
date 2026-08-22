import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Building2 } from 'lucide-react';
import { ClientForm } from '@/components/clients/client-form';
import type { ClientRecord } from '@/components/clients/client-form';

export default function ClientsEdit({ client }: { client: ClientRecord }) {
    return (
        <>
            <Head title={`تعديل ${client.name}`} />
            <div className="cloudtech-page client-form-page">
                <Link
                    className="project-back-link"
                    href={`/clients/${client.id}`}
                >
                    <ArrowRight aria-hidden="true" />
                    العودة إلى ملف العميل
                </Link>
                <header className="client-form-hero">
                    <div className="cloudtech-empty-icon">
                        <Building2 aria-hidden="true" />
                    </div>
                    <div>
                        <p className="cloudtech-eyebrow" dir="ltr">
                            {client.code}
                        </p>
                        <h1 tabIndex={-1}>تعديل بيانات العميل</h1>
                        <p>
                            حدّث بيانات {client.name} مع الحفاظ على المشاريع
                            وجهات الاتصال المرتبطة.
                        </p>
                    </div>
                </header>
                <section
                    className="client-form-panel"
                    aria-label="تعديل بيانات العميل"
                >
                    <ClientForm client={client} />
                </section>
            </div>
        </>
    );
}

ClientsEdit.layout = {
    breadcrumbs: [
        { title: 'العملاء', href: '/clients' },
        { title: 'تعديل العميل', href: '#' },
    ],
};

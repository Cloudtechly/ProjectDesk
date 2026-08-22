import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Building2 } from 'lucide-react';
import { ClientForm } from '@/components/clients/client-form';

export default function ClientsCreate() {
    return (
        <>
            <Head title="إضافة عميل" />
            <div className="cloudtech-page client-form-page">
                <Link className="project-back-link" href="/clients">
                    <ArrowRight aria-hidden="true" />
                    العودة إلى العملاء
                </Link>
                <header className="client-form-hero">
                    <div className="cloudtech-empty-icon">
                        <Building2 aria-hidden="true" />
                    </div>
                    <div>
                        <p className="cloudtech-eyebrow">علاقة جديدة</p>
                        <h1 tabIndex={-1}>إضافة عميل</h1>
                        <p>
                            ابدأ ببيانات الجهة الأساسية، ثم أضف جهات الاتصال
                            والمشاريع من ملف العميل.
                        </p>
                    </div>
                </header>
                <section
                    className="client-form-panel"
                    aria-label="بيانات العميل"
                >
                    <ClientForm />
                </section>
            </div>
        </>
    );
}

ClientsCreate.layout = {
    breadcrumbs: [
        { title: 'العملاء', href: '/clients' },
        { title: 'إضافة عميل', href: '/clients/create' },
    ],
};

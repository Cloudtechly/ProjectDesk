import type {
    InvoiceClientPreview,
    InvoiceCompanyPreview,
} from './invoice-template-preview';

export type SalesSummary = {
    id: number;
    number: string;
    type: 'invoice';
    title: string;
    client?: string | null;
    project?: string | null;
    issueDate?: string | null;
    dueDate?: string | null;
    currency: 'LYD' | 'USD' | 'EUR';
    status: 'draft' | 'archived';
    totals?: {
        subtotal: string;
        discount: string;
        tax_base: string;
        tax: string;
        total: string;
    } | null;
};

export type SalesDocument = SalesSummary & {
    reference?: string | null;
    notes?: string | null;
    clientId?: number | null;
    projectId?: number | null;
    storedStatus: 'draft' | 'archived';
    discountRate: string | number;
    taxRate: string | number;
    lockVersion: number;
    clientSnapshot?: InvoiceClientPreview | null;
    companySnapshot?: InvoiceCompanyPreview | null;
    lineItems: Array<{
        id?: number;
        name: string;
        description?: string | null;
        quantity: string | number;
        unit: string;
        unitPrice: string | number;
    }>;
    permissions?: {
        update?: boolean;
        archive?: boolean;
        restore?: boolean;
        duplicate?: boolean;
    };
};

export type Option = {
    id: number;
    name: string;
    client_id?: number | null;
    email?: string | null;
    phone?: string | null;
    address?: string | null;
    primary_contact?: InvoiceClientPreview['primary_contact'];
};

export type Paginator<T> = {
    data: T[];
    current_page?: number;
    last_page?: number;
    prev_page_url?: string | null;
    next_page_url?: string | null;
};

export type SalesProps = {
    documents?: Paginator<SalesSummary> | SalesSummary[];
    filters?: { q?: string; status?: string; project?: string | number };
    projects?: Option[];
    clients?: Option[];
    formProjects?: Option[];
    formClients?: Option[];
    company?: InvoiceCompanyPreview;
    canCreate?: boolean;
};

export type LineItem = {
    name: string;
    description: string;
    quantity: string;
    unit: string;
    unit_price: string;
};

function localDate(daysFromToday = 0) {
    const date = new Date();
    date.setDate(date.getDate() + daysFromToday);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function initialLine(): LineItem {
    return {
        name: '',
        description: '',
        quantity: '1',
        unit: 'مشروع',
        unit_price: '0.00',
    };
}

export function initialInvoiceForm() {
    const english =
        typeof document !== 'undefined' &&
        document.documentElement.lang.toLowerCase().startsWith('en');

    return {
        type: 'invoice' as const,
        title: english ? 'Technical services invoice' : 'فاتورة خدمات تقنية',
        status: 'draft' as const,
        client_id: '',
        project_id: '',
        issue_date: localDate(),
        due_date: localDate(14),
        reference: '',
        currency: 'LYD' as 'LYD' | 'USD' | 'EUR',
        discount_rate: '0',
        tax_rate: '0',
        notes: english
            ? 'Prices exclude hosting, domains, licences, and external services unless explicitly listed in the items.'
            : 'لا تشمل الأسعار رسوم الاستضافة والنطاق والتراخيص والخدمات الخارجية إلا إذا ذُكرت صراحة ضمن البنود.',
        lock_version: '',
        line_items: [initialLine()],
    };
}

export type InvoiceFormData = ReturnType<typeof initialInvoiceForm>;

export function collection<T>(value?: Paginator<T> | T[]) {
    return Array.isArray(value) ? value : (value?.data ?? []);
}

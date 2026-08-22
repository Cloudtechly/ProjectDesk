import {
    calculateInvoiceLineTotal,
    truncateInvoiceMoney,
} from '@/lib/invoice-calculator';

type InvoiceLine = {
    name: string;
    description: string;
    quantity: string;
    unit: string;
    unit_price: string;
};

export type InvoiceClientPreview = {
    id?: number;
    name?: string | null;
    email?: string | null;
    phone?: string | null;
    address?: string | null;
    primary_contact?: {
        name?: string | null;
        role?: string | null;
        email?: string | null;
        phone?: string | null;
    } | null;
};

export type InvoiceCompanyPreview = {
    name?: string | null;
    display_name?: string | null;
    legal_name?: string | null;
    email?: string | null;
    phone?: string | null;
    address?: string | null;
    website?: string | null;
    logo_asset?: string | null;
};

type InvoiceTotals = {
    subtotal: number;
    discount: number;
    tax: number;
    total: number;
};

type InvoiceTemplatePreviewProps = {
    title: string;
    number: string;
    reference: string;
    issueDate: string;
    dueDate: string;
    currency: string;
    discountRate: string;
    taxRate: string;
    notes: string;
    items: InvoiceLine[];
    totals: InvoiceTotals;
    client?: InvoiceClientPreview | null;
    company?: InvoiceCompanyPreview | null;
    formatMoney: (value: number) => string;
};

const ITEMS_PER_PAGE = 7;

function invoiceDate(value: string) {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.split('-');

    return year && month && day ? `${day}/${month}/${year}` : value;
}

function chunks<T>(items: T[], size: number) {
    const result: T[][] = [];

    for (let index = 0; index < items.length; index += size) {
        result.push(items.slice(index, index + size));
    }

    return result.length > 0 ? result : [[]];
}

function dataValue(value?: string | null) {
    return value?.trim() || null;
}

export default function InvoiceTemplatePreview({
    title,
    number,
    reference,
    issueDate,
    dueDate,
    currency,
    discountRate,
    taxRate,
    notes,
    items,
    totals,
    client,
    company,
    formatMoney,
}: InvoiceTemplatePreviewProps) {
    const pages = chunks(items, ITEMS_PER_PAGE);
    const companyName =
        dataValue(company?.display_name) ||
        dataValue(company?.name) ||
        dataValue(company?.legal_name) ||
        'CloudTech';
    const companyEmail = dataValue(company?.email) || 'info@cloudtech.ly';
    const companyPhone = dataValue(company?.phone) || '0926169188 - 0917985603';
    const companyWebsite =
        dataValue(company?.website)?.replace(/^https?:\/\//u, '') ||
        '@cloudtech.ly';
    const clientName = dataValue(client?.name) || 'اسم الشركة / العميل';
    const clientDetails = [
        dataValue(client?.primary_contact?.name),
        dataValue(client?.email) || dataValue(client?.primary_contact?.email),
        dataValue(client?.phone) || dataValue(client?.primary_contact?.phone),
        dataValue(client?.address),
    ].filter((value): value is string => Boolean(value));

    return (
        <div className="sales-invoice-pages">
            {pages.map((pageItems, pageIndex) => {
                const isLastPage = pageIndex === pages.length - 1;

                return (
                    <article
                        className="sales-a4-preview sales-invoice-sheet"
                        data-testid="invoice-sheet"
                        data-page={pageIndex + 1}
                        dir="rtl"
                        key={pageIndex}
                    >
                        <div className="sales-invoice-page-body">
                            <header
                                className="sales-invoice-banner"
                                data-testid="invoice-banner"
                            >
                                <img
                                    src={
                                        dataValue(company?.logo_asset) ||
                                        '/brand/cloudtech-logo.svg'
                                    }
                                    alt={companyName}
                                    className="sales-invoice-logo"
                                    data-testid="invoice-logo"
                                />
                                <div className="sales-invoice-heading">
                                    <strong>فاتورة</strong>
                                    <span>INVOICE</span>
                                </div>
                            </header>

                            <div className="sales-invoice-meta">
                                <section
                                    className="sales-invoice-client"
                                    data-testid="invoice-client"
                                >
                                    <small>فاتورة إلى</small>
                                    <h3 data-no-translate>{clientName}</h3>
                                    <p data-no-translate>
                                        {clientDetails.length > 0
                                            ? clientDetails.map(
                                                  (detail, detailIndex) => (
                                                      <span key={detailIndex}>
                                                          {detail}
                                                      </span>
                                                  ),
                                              )
                                            : 'تُستكمل بيانات العميل'}
                                    </p>
                                </section>

                                <dl
                                    className="sales-invoice-reference-list"
                                    data-testid="invoice-reference-list"
                                >
                                    <div>
                                        <dt>رقم الفاتورة</dt>
                                        <dd>
                                            <bdi dir="ltr" data-no-translate>
                                                {number}
                                            </bdi>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>تاريخ الإصدار</dt>
                                        <dd>
                                            <bdi dir="ltr">
                                                {invoiceDate(issueDate)}
                                            </bdi>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>تاريخ الاستحقاق</dt>
                                        <dd>
                                            <bdi dir="ltr">
                                                {invoiceDate(dueDate)}
                                            </bdi>
                                        </dd>
                                    </div>
                                    {reference && (
                                        <div>
                                            <dt>المرجع</dt>
                                            <dd data-no-translate>
                                                {reference}
                                            </dd>
                                        </div>
                                    )}
                                </dl>
                            </div>

                            <div
                                className="sales-invoice-kicker"
                                data-no-translate={Boolean(title)}
                            >
                                {title || 'فاتورة الخدمات'}
                            </div>
                            <h3 className="sales-invoice-section-title">
                                <span>البنود والقيمة المالية</span>
                                {pages.length > 1 && (
                                    <bdi dir="ltr"> — {pageIndex + 1}</bdi>
                                )}
                            </h3>

                            <table
                                className="sales-invoice-lines"
                                data-testid="invoice-lines"
                            >
                                <thead>
                                    <tr>
                                        <th scope="col">البيان والتفاصيل</th>
                                        <th scope="col">العدد</th>
                                        <th scope="col">الوحدة</th>
                                        <th scope="col">السعر</th>
                                        <th scope="col">الإجمالي</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {pageItems.map((item, itemIndex) => {
                                        const quantity = item.quantity || '0';
                                        const unitPrice =
                                            item.unit_price || '0';

                                        return (
                                            <tr key={itemIndex}>
                                                <td>
                                                    <strong data-no-translate>
                                                        {item.name || 'بند'}
                                                    </strong>
                                                    {item.description && (
                                                        <small
                                                            data-no-translate
                                                        >
                                                            {item.description}
                                                        </small>
                                                    )}
                                                </td>
                                                <td>
                                                    <bdi dir="ltr">
                                                        {quantity}
                                                    </bdi>
                                                </td>
                                                <td data-no-translate>
                                                    {item.unit || '—'}
                                                </td>
                                                <td>
                                                    <bdi dir="ltr">
                                                        {formatMoney(
                                                            truncateInvoiceMoney(
                                                                unitPrice,
                                                            ),
                                                        )}{' '}
                                                        {currency}
                                                    </bdi>
                                                </td>
                                                <td>
                                                    <bdi dir="ltr">
                                                        {formatMoney(
                                                            calculateInvoiceLineTotal(
                                                                quantity,
                                                                unitPrice,
                                                            ),
                                                        )}{' '}
                                                        {currency}
                                                    </bdi>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>

                            {isLastPage ? (
                                <>
                                    <dl
                                        className="sales-invoice-totals"
                                        data-testid="invoice-totals"
                                    >
                                        <div>
                                            <dt>المجموع الفرعي</dt>
                                            <dd dir="ltr">
                                                {formatMoney(totals.subtotal)}{' '}
                                                {currency}
                                            </dd>
                                        </div>
                                        {totals.discount > 0 && (
                                            <div>
                                                <dt>
                                                    الخصم ({discountRate || 0}%)
                                                </dt>
                                                <dd dir="ltr">
                                                    -{' '}
                                                    {formatMoney(
                                                        totals.discount,
                                                    )}{' '}
                                                    {currency}
                                                </dd>
                                            </div>
                                        )}
                                        {totals.tax > 0 && (
                                            <div>
                                                <dt>
                                                    الضريبة ({taxRate || 0}%)
                                                </dt>
                                                <dd dir="ltr">
                                                    {formatMoney(totals.tax)}{' '}
                                                    {currency}
                                                </dd>
                                            </div>
                                        )}
                                        <div className="is-final">
                                            <dt>الإجمالي</dt>
                                            <dd dir="ltr">
                                                {formatMoney(totals.total)}{' '}
                                                {currency}
                                            </dd>
                                        </div>
                                    </dl>
                                    {notes && (
                                        <p
                                            className="sales-invoice-pricing-note"
                                            data-no-translate
                                        >
                                            {notes}
                                        </p>
                                    )}
                                </>
                            ) : (
                                <div className="sales-invoice-continuation">
                                    <strong>استكمال البنود</strong>
                                    <span>
                                        تستكمل بقية البنود في الصفحة التالية،
                                        ويظهر الإجمالي في الصفحة الأخيرة.
                                    </span>
                                </div>
                            )}

                            <footer
                                className="sales-invoice-footer"
                                data-testid="invoice-footer"
                            >
                                <div data-no-translate>
                                    <bdi dir="ltr">
                                        {companyName} | {companyEmail}
                                    </bdi>
                                    <bdi dir="ltr">Phone {companyPhone}</bdi>
                                </div>
                                <bdi
                                    dir="ltr"
                                    className="sales-invoice-page-number"
                                    data-testid="invoice-page-number"
                                >
                                    {pageIndex + 1} / {pages.length}
                                </bdi>
                                <div data-no-translate>
                                    <bdi dir="ltr">{companyWebsite}</bdi>
                                    {company?.address && (
                                        <span>{company.address}</span>
                                    )}
                                </div>
                            </footer>
                        </div>
                    </article>
                );
            })}
        </div>
    );
}

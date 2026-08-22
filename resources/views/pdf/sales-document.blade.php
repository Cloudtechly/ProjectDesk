<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>فاتورة — {{ $document->title }}</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { direction: rtl; color: #244546; background: #fff; font-family: dejavusans, sans-serif; }
        .invoice-page { position: relative; width: 210mm; height: 297mm; overflow: hidden; padding: 15.35mm 15.35mm 22.2mm; background: #fff; }
        .financial-banner { position: relative; height: 46.3mm; margin-bottom: 6.9mm; overflow: hidden; border-radius: 6.6mm; color: #fff; background: #42698f; }
        .financial-banner-image { display: block; width: 179.3mm; height: 46.3mm; }
        .financial-banner-semantic { display: none; }
        .financial-meta { width: 100%; margin: 0 0 6.1mm; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        .financial-meta > tbody > tr > td { vertical-align: top; }
        .financial-meta .client-cell { width: 60%; padding: 4.2mm; border: .3mm solid #d9e3eb; border-radius: 4.2mm; background: #fff; }
        .financial-meta .refs-cell { width: 40%; padding-right: 6mm; }
        .financial-box { min-height: 28mm; padding: 4.2mm; border: .3mm solid #d9e3eb; border-radius: 4.2mm; background: #fff; }
        .financial-box small { display: block; margin-bottom: 1.3mm; color: #168d96; font-size: 7.5pt; }
        .financial-box h2 { margin: 0 0 1.8mm; color: #42698f; font-size: 14pt; font-weight: bold; }
        .financial-box p { margin: 0; color: #6f8081; font-size: 8pt; line-height: 1.65; white-space: pre-line; }
        .financial-ref { margin-bottom: 2.1mm; padding: 2.4mm 2.7mm; border-radius: 2.6mm; color: #687f89; background: #f0f5f8; font-size: 7.4pt; }
        .financial-ref strong { float: left; direction: ltr; color: #42698f; font-weight: bold; text-align: left; }
        .page-kicker { overflow: hidden; color: #168d96; font-size: 7.5pt; font-weight: bold; letter-spacing: 1.2pt; text-overflow: ellipsis; text-transform: uppercase; white-space: nowrap; }
        .page-title { margin: 1.6mm 0 3mm; color: #42698f; font-size: 21pt; font-weight: bold; line-height: 1.2; }
        .services-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0 2.1mm; table-layout: fixed; }
        .services-table thead { display: table-header-group; }
        .services-table tr { page-break-inside: avoid; }
        .services-table th { padding: 2.7mm 2.1mm; color: #fff; background: #42698f; font-size: 7.7pt; font-weight: bold; text-align: center; }
        .services-table th:first-child { width: 39%; border-radius: 0 2.1mm 2.1mm 0; color: #315474; background: #39c4cb; text-align: right; }
        .services-table th:last-child { width: 17%; border-radius: 2.1mm 0 0 2.1mm; }
        .services-table td { padding: 2.9mm 2.1mm; color: #536f72; background: #f1f4f3; font-size: 7.6pt; text-align: center; vertical-align: top; }
        .services-table td:first-child { border-radius: 0 2.1mm 2.1mm 0; text-align: right; }
        .services-table td:last-child { border-radius: 2.1mm 0 0 2.1mm; color: #42698f; font-weight: bold; }
        .service-name { margin-bottom: .8mm; color: #42698f; font-size: 8.7pt; font-weight: bold; line-height: 1.3; }
        .service-description { max-height: 11.4mm; overflow: hidden; color: #778687; font-size: 7pt; line-height: 1.5; white-space: pre-line; }
        .numeric { direction: ltr; white-space: nowrap; }
        .totals { float: right; width: 84.7mm; margin-top: 5.8mm; padding: 3.7mm 4.2mm; border: .3mm solid #d9e3eb; border-radius: 4.2mm; background: #f7faf9; page-break-inside: avoid; }
        .total-row { min-height: 5.5mm; color: #536f72; font-size: 8pt; }
        .total-row strong { float: left; direction: ltr; font-weight: normal; text-align: left; }
        .total-row.final { margin-top: 1.5mm; padding-top: 2.7mm; border-top: .6mm solid #42698f; color: #42698f; font-size: 13pt; font-weight: bold; }
        .total-row.final strong { color: #168d96; font-weight: bold; }
        .pricing-note { clear: both; margin: 2mm 0 0; color: #7d8a8b; font-size: 6.8pt; line-height: 1.55; white-space: pre-line; }
        .continuation { margin-top: 5.8mm; padding: 4mm; border: .3mm solid #d9e3eb; border-radius: 3mm; color: #6f8081; background: #f7faf9; font-size: 8pt; }
        .continuation strong { display: block; margin-bottom: 1mm; color: #42698f; }
        .page-decoration { position: absolute; z-index: 0; left: -4mm; bottom: 12mm; width: 69mm; height: 27.8mm; border-radius: 55% 45% 0 0; transform: rotate(-8deg); background: #dff6f7; }
        .page-footer { width: 179.3mm; border-top: .3mm solid #d9e4e1; padding-top: 2.7mm; color: #728283; font-size: 6.4pt; }
        .page-footer table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .page-footer td { width: 33.33%; vertical-align: bottom; }
        .footer-contact { direction: ltr; text-align: left; line-height: 1.55; }
        .footer-page { text-align: center; }
        .footer-page span { display: inline-block; direction: ltr; padding: 1.1mm 1.8mm; border-radius: 1.8mm; color: #42698f; background: #f0f5f8; font-weight: bold; }
        .footer-web { text-align: right; line-height: 1.55; }
    </style>
</head>
<body>
@php
    $company = is_array($document->company_snapshot) ? $document->company_snapshot : [];
    $client = is_array($document->client_snapshot) ? $document->client_snapshot : [];
    $primaryContact = is_array(data_get($client, 'primary_contact')) ? data_get($client, 'primary_contact') : [];
    $clientName = data_get($client, 'name', $document->client?->name) ?: 'اسم الشركة / العميل';
    $clientDetails = array_values(array_filter([
        data_get($primaryContact, 'name'),
        data_get($client, 'email', data_get($primaryContact, 'email')),
        data_get($client, 'phone', data_get($primaryContact, 'phone')),
        data_get($client, 'address'),
    ], fn ($value) => is_string($value) && $value !== ''));
    $companyName = data_get($company, 'display_name', data_get($company, 'name', data_get($company, 'legal_name', 'CloudTech'))) ?: 'CloudTech';
    $companyEmail = data_get($company, 'email') ?: 'info@cloudtech.ly';
    $companyPhone = data_get($company, 'phone') ?: '0926169188 - 0917985603';
    $companyWebsite = preg_replace('/^https?:\/\//', '', (string) (data_get($company, 'website') ?: '@cloudtech.ly'));
    $companyAddress = data_get($company, 'address');
    $logoAsset = (string) (data_get($company, 'logo_asset') ?: '/brand/cloudtech-logo.svg');
    $logoPath = public_path(ltrim((string) (parse_url($logoAsset, PHP_URL_PATH) ?: '/brand/cloudtech-logo.svg'), '/'));
    $publicRoot = realpath(public_path());
    $resolvedLogo = realpath($logoPath);
    if (! $resolvedLogo || ! $publicRoot || ! str_starts_with($resolvedLogo, $publicRoot.DIRECTORY_SEPARATOR)) {
        $resolvedLogo = realpath(public_path('brand/cloudtech-logo.svg'));
    }
    $logoMime = match (strtolower((string) pathinfo((string) $resolvedLogo, PATHINFO_EXTENSION))) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        default => 'image/svg+xml',
    };
    $logoSource = $resolvedLogo ? 'data:'.$logoMime.';base64,'.base64_encode((string) file_get_contents($resolvedLogo)) : '';
    $bannerPath = public_path('brand/cloudtech-invoice-banner.svg');
    $bannerSource = is_file($bannerPath)
        ? 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents($bannerPath))
        : $logoSource;
    $pages = $document->lineItems->chunk(7);
    if ($pages->isEmpty()) {
        $pages = collect([collect()]);
    }
    $formatMoney = static function (string|int|float $value) use ($document): string {
        $formatted = rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');

        return $formatted.' '.$document->currency;
    };
    $trimDecimal = static function (string|int|float $value): string {
        $formatted = (string) $value;

        return str_contains($formatted, '.')
            ? rtrim(rtrim($formatted, '0'), '.')
            : $formatted;
    };
@endphp

@foreach($pages as $pageItems)
    <section class="invoice-page" data-region="invoice-sheet">
        <div class="financial-banner" data-region="invoice-banner">
            @if($bannerSource)
                <img class="financial-banner-image" data-region="invoice-logo" src="{{ $bannerSource }}" alt="{{ $companyName }}">
            @endif
            <span class="financial-banner-semantic">فاتورة INVOICE</span>
        </div>

        <table class="financial-meta"><tbody><tr>
            <td class="client-cell" data-region="invoice-client">
                <small>فاتورة إلى</small>
                <h2>{{ $clientName }}</h2>
                <p>{{ $clientDetails === [] ? 'تُستكمل بيانات العميل' : implode("\n", $clientDetails) }}</p>
            </td>
            <td class="refs-cell" data-region="invoice-reference-list">
                    <div class="financial-ref">رقم الفاتورة <strong>{{ $document->number }}</strong></div>
                    <div class="financial-ref">تاريخ الإصدار <strong>{{ $document->issue_date?->format('d/m/Y') ?? '—' }}</strong></div>
                    <div class="financial-ref">تاريخ الاستحقاق <strong>{{ $document->due_date?->format('d/m/Y') ?? '—' }}</strong></div>
                    @if($document->reference)
                        <div class="financial-ref">المرجع <strong>{{ $document->reference }}</strong></div>
                    @endif
            </td>
        </tr></tbody></table>

        <div class="page-kicker">{{ $document->title ?: 'فاتورة الخدمات' }}</div>
        <h1 class="page-title">البنود والقيمة المالية @if($pages->count() > 1)<span class="numeric">— {{ $loop->iteration }}</span>@endif</h1>

        <table class="services-table" data-region="invoice-lines">
            <thead><tr>
                <th scope="col">البيان والتفاصيل</th>
                <th scope="col">العدد</th>
                <th scope="col">الوحدة</th>
                <th scope="col">السعر</th>
                <th scope="col">الإجمالي</th>
            </tr></thead>
            <tbody>
            @forelse($pageItems as $item)
                @php($lineTotal = bcadd(bcmul($item->quantity, $item->unit_price, 4), '0', 2))
                <tr>
                    <td><div class="service-name">{{ $item->name }}</div>@if($item->description)<div class="service-description">{{ $item->description }}</div>@endif</td>
                    <td class="numeric">{{ $trimDecimal($item->quantity) }}</td>
                    <td>{{ $item->unit }}</td>
                    <td class="numeric">{{ $formatMoney(bcadd($item->unit_price, '0', 2)) }}</td>
                    <td class="numeric">{{ $formatMoney($lineTotal) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">لا توجد بنود مضافة</td></tr>
            @endforelse
            </tbody>
        </table>

        @if($loop->last)
            <div class="totals" data-region="invoice-totals">
                <div class="total-row">المجموع الفرعي <strong>{{ $formatMoney($document->subtotal) }}</strong></div>
                @if(bccomp($document->discount_amount, '0', 2) === 1)
                    <div class="total-row">الخصم ({{ $trimDecimal($document->discount_rate) }}%) <strong>- {{ $formatMoney($document->discount_amount) }}</strong></div>
                @endif
                @if(bccomp($document->tax_amount, '0', 2) === 1)
                    <div class="total-row">الضريبة ({{ $trimDecimal($document->tax_rate) }}%) <strong>{{ $formatMoney($document->tax_amount) }}</strong></div>
                @endif
                <div class="total-row final">الإجمالي <strong>{{ $formatMoney($document->total) }}</strong></div>
            </div>
            @if($document->notes)
                <p class="pricing-note">{{ $document->notes }}</p>
            @endif
        @else
            <div class="continuation"><strong>استكمال البنود</strong>تستكمل بقية البنود في الصفحة التالية، ويظهر الإجمالي في الصفحة الأخيرة.</div>
        @endif

        <span class="page-decoration"></span>
        <footer class="page-footer" data-region="invoice-footer">
            <table><tr>
                <td class="footer-contact">
                    {{ $companyName }} | {{ $companyEmail }}
                    <br>Phone {{ $companyPhone }}
                </td>
                <td class="footer-page"><span data-region="invoice-page-number">{{ $loop->iteration }} / {{ $pages->count() }}</span></td>
                <td class="footer-web">
                    <span dir="ltr">{{ $companyWebsite }}</span>
                    @if($companyAddress)<br>{{ $companyAddress }}@endif
                </td>
            </tr></table>
        </footer>
    </section>
    @unless($loop->last)
        <pagebreak />
    @endunless
@endforeach
</body>
</html>

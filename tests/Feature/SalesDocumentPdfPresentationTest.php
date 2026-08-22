<?php

namespace Tests\Feature;

use App\Models\SalesDocument;
use App\Services\SalesCalculator;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class SalesDocumentPdfPresentationTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_reference_invoice_html_renders_the_complete_semantic_contract(): void
    {
        [$document, $totals] = $this->makeReferenceInvoice(7);
        [$html, $xpath] = $this->renderInvoiceHtml($document, $totals);

        $sheets = $this->nodes($xpath, '//*[@data-region="invoice-sheet"]');
        $this->assertCount(1, $sheets);
        $sheet = $sheets[0];

        foreach ([
            'invoice-banner',
            'invoice-logo',
            'invoice-client',
            'invoice-reference-list',
            'invoice-lines',
            'invoice-totals',
            'invoice-footer',
            'invoice-page-number',
        ] as $region) {
            $this->assertSame(
                1,
                $this->regionCount($xpath, $region, $sheet),
                "Expected one [data-region={$region}] inside the invoice sheet.",
            );
        }

        $banner = $this->singleRegion($xpath, 'invoice-banner', $sheet);
        $this->assertStringContainsString('فاتورة', $this->text($banner));
        $this->assertStringContainsString('INVOICE', $this->text($banner));

        $logo = $this->singleRegion($xpath, 'invoice-logo', $sheet);
        $this->assertInstanceOf(DOMElement::class, $logo);
        $this->assertSame('img', strtolower($logo->tagName));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $logo->getAttribute('src'));
        $this->assertSame('CloudTech Reference', $logo->getAttribute('alt'));

        $client = $this->text($this->singleRegion($xpath, 'invoice-client', $sheet));
        foreach ([
            'شركة المدار للحلول',
            'سارة السنوسي',
            'client@example.test',
            '+218 91 000 0000',
            'طرابلس، ليبيا',
        ] as $clientValue) {
            $this->assertStringContainsString($clientValue, $client);
        }

        $references = $this->text($this->singleRegion($xpath, 'invoice-reference-list', $sheet));
        foreach (['CT-INV-2026-007', '14/08/2026', '28/08/2026', 'REF-CONTRACT-42'] as $referenceValue) {
            $this->assertStringContainsString($referenceValue, $references);
        }

        $lines = $this->singleRegion($xpath, 'invoice-lines', $sheet);
        $this->assertInstanceOf(DOMElement::class, $lines);
        $this->assertSame('table', strtolower($lines->tagName));
        $this->assertSame(
            ['البيان والتفاصيل', 'العدد', 'الوحدة', 'السعر', 'الإجمالي'],
            array_map(
                fn (DOMNode $node): string => $this->text($node),
                $this->nodes($xpath, './/thead//th', $lines),
            ),
        );
        $this->assertCount(7, $this->nodes($xpath, './/tbody/tr', $lines));

        for ($index = 1; $index <= 7; $index++) {
            $this->assertSame(1, substr_count($html, "خدمة مرجعية {$index}"));
            $this->assertSame(1, substr_count($html, "وصف الخدمة المرجعية {$index}"));
        }

        $totalsText = str_replace([',', ' '], '', $this->text(
            $this->singleRegion($xpath, 'invoice-totals', $sheet),
        ));
        foreach (['2800LYD', '280LYD', '378LYD', '2898LYD'] as $moneyValue) {
            $this->assertStringContainsString($moneyValue, $totalsText);
        }

        $sheetText = $this->text($sheet);
        foreach ([
            'قالب فاتورة الخدمات المرجعي',
            'ملاحظة التسعير المرجعية',
            'CloudTech Reference',
            'billing@cloudtech.test',
            '+218 21 000 0000',
            'cloudtech.test',
            'حي الأندلس، طرابلس',
            '1 / 1',
        ] as $visibleValue) {
            $this->assertStringContainsString($visibleValue, $sheetText);
        }
    }

    public function test_reference_invoice_html_chunks_eight_items_as_seven_then_one(): void
    {
        [$document, $totals] = $this->makeReferenceInvoice(8);
        [$html, $xpath] = $this->renderInvoiceHtml($document, $totals);

        $sheets = $this->nodes($xpath, '//*[@data-region="invoice-sheet"]');
        $this->assertCount(2, $sheets);

        $firstLines = $this->singleRegion($xpath, 'invoice-lines', $sheets[0]);
        $secondLines = $this->singleRegion($xpath, 'invoice-lines', $sheets[1]);
        $this->assertCount(7, $this->nodes($xpath, './/tbody/tr', $firstLines));
        $this->assertCount(1, $this->nodes($xpath, './/tbody/tr', $secondLines));

        $this->assertSame(0, $this->regionCount($xpath, 'invoice-totals', $sheets[0]));
        $this->assertSame(1, $this->regionCount($xpath, 'invoice-totals', $sheets[1]));
        $this->assertStringContainsString('استكمال البنود', $this->text($sheets[0]));
        $this->assertStringNotContainsString('ملاحظة التسعير المرجعية', $this->text($sheets[0]));
        $this->assertStringContainsString('ملاحظة التسعير المرجعية', $this->text($sheets[1]));

        $this->assertSame(
            '1 / 2',
            $this->text($this->singleRegion($xpath, 'invoice-page-number', $sheets[0])),
        );
        $this->assertSame(
            '2 / 2',
            $this->text($this->singleRegion($xpath, 'invoice-page-number', $sheets[1])),
        );

        for ($index = 1; $index <= 8; $index++) {
            $this->assertSame(1, substr_count($html, "خدمة مرجعية {$index}"));
        }

        foreach ($sheets as $sheet) {
            foreach ([
                'invoice-banner',
                'invoice-logo',
                'invoice-client',
                'invoice-reference-list',
                'invoice-lines',
                'invoice-footer',
                'invoice-page-number',
            ] as $region) {
                $this->assertSame(
                    1,
                    $this->regionCount($xpath, $region, $sheet),
                    "Expected one [data-region={$region}] on every invoice page.",
                );
            }
        }
    }

    public function test_reference_invoice_pdf_keeps_the_same_two_page_chunking(): void
    {
        [$document] = $this->makeReferenceInvoice(8);
        $actor = $document->creator()->firstOrFail();

        $response = $this->actingAs($actor)->get(route('sales.pdf', $document));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');

        $pdf = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression(
            '/\/Type \/Pages\s+\/Kids \[[^]]+\]\s+\/Count 2\b/s',
            $pdf,
            'The downloadable PDF must contain exactly the same two A4 pages as the 7+1 preview.',
        );
    }

    /**
     * @return array{0: SalesDocument, 1: array{subtotal: string, discount: string, tax_base: string, tax: string, total: string}}
     */
    private function makeReferenceInvoice(int $lineCount): array
    {
        $creator = $this->makeUser();
        $document = SalesDocument::query()->create([
            'type' => SalesDocument::TEMPLATE_TYPE,
            'number' => 'CT-INV-2026-007',
            'title' => 'قالب فاتورة الخدمات المرجعي',
            'status' => 'draft',
            'client_id' => null,
            'project_id' => null,
            'issue_date' => '2026-08-14',
            'due_date' => '2026-08-28',
            'reference' => 'REF-CONTRACT-42',
            'currency' => 'LYD',
            'discount_rate' => '10.00',
            'tax_rate' => '15.00',
            'notes' => 'ملاحظة التسعير المرجعية',
            'client_snapshot' => [
                'name' => 'شركة المدار للحلول',
                'email' => 'client@example.test',
                'phone' => '+218 91 000 0000',
                'address' => 'طرابلس، ليبيا',
                'primary_contact' => [
                    'name' => 'سارة السنوسي',
                    'role' => 'مديرة المشروع',
                    'email' => 'sara@example.test',
                    'phone' => '+218 92 000 0000',
                ],
            ],
            'company_snapshot' => [
                'name' => 'CloudTech Reference',
                'display_name' => 'CloudTech Reference',
                'legal_name' => 'CloudTech Reference LLC',
                'email' => 'billing@cloudtech.test',
                'phone' => '+218 21 000 0000',
                'website' => 'https://cloudtech.test',
                'address' => 'حي الأندلس، طرابلس',
                'logo_asset' => '/brand/reference-invoice-logo.svg',
            ],
            'lock_version' => 1,
            'created_by' => $creator->id,
        ]);

        $lineItems = [];
        for ($index = 1; $index <= $lineCount; $index++) {
            $lineItems[] = [
                'name' => "خدمة مرجعية {$index}",
                'description' => "وصف الخدمة المرجعية {$index}",
                'quantity' => '1.000',
                'unit' => 'مشروع',
                'unit_price' => number_format($index * 100, 2, '.', ''),
                'position' => $index,
            ];
        }
        $document->lineItems()->createMany($lineItems);
        $document = $document->fresh()->load(['client', 'project', 'lineItems']);

        $calculatorItems = $document->lineItems->map(fn ($item): array => [
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
        ])->all();
        $totals = app(SalesCalculator::class)->calculate(
            $calculatorItems,
            $document->discount_rate,
            $document->tax_rate,
        );
        $document->setAttribute('subtotal', $totals['subtotal']);
        $document->setAttribute('discount_amount', $totals['discount']);
        $document->setAttribute('tax_amount', $totals['tax']);
        $document->setAttribute('total', $totals['total']);

        return [$document, $totals];
    }

    /**
     * @param  array{subtotal: string, discount: string, tax_base: string, tax: string, total: string}  $totals
     * @return array{0: string, 1: DOMXPath}
     */
    private function renderInvoiceHtml(SalesDocument $document, array $totals): array
    {
        $html = view('pdf.sales-document', [
            'document' => $document,
            'totals' => $totals,
            'typeLabel' => 'قالب فاتورة',
            'statusLabel' => 'مسودة',
        ])->render();

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $this->assertTrue($loaded, 'The invoice Blade view must render parseable HTML.');

        return [$html, new DOMXPath($dom)];
    }

    /** @return list<DOMNode> */
    private function nodes(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        $result = $xpath->query($expression, $context);
        $this->assertNotFalse($result, "Invalid XPath expression: {$expression}");

        return iterator_to_array($result, false);
    }

    private function regionCount(DOMXPath $xpath, string $region, ?DOMNode $context = null): int
    {
        return count($this->nodes($xpath, ".//*[@data-region=\"{$region}\"]", $context));
    }

    private function singleRegion(DOMXPath $xpath, string $region, ?DOMNode $context = null): DOMNode
    {
        $nodes = $this->nodes($xpath, ".//*[@data-region=\"{$region}\"]", $context);
        $this->assertCount(1, $nodes, "Expected one [data-region={$region}].");

        return $nodes[0];
    }

    private function text(DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }
}

<?php

namespace App\Services;

use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\SalesLineItem;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class PdfExportService
{
    public function __construct(
        private readonly SalesCalculator $calculator,
        private readonly ActivityLogger $activityLogger,
        private readonly Filesystem $files,
        private readonly ProjectMetrics $projectMetrics,
    ) {}

    public function salesDocument(SalesDocument $document, User $actor, Request $request): Response
    {
        abort_unless($document->isInvoiceTemplate(), 404);
        $document->loadMissing(['client', 'project', 'lineItems']);
        $items = $document->lineItems->map(fn (SalesLineItem $item): array => [
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
        ])->all();
        $totals = $this->calculator->calculate($items, $document->discount_rate, $document->tax_rate);
        // The invoice-template view reads these transient presentation values.
        // They are never persisted and therefore cannot become accounting state.
        $document->setAttribute('subtotal', $totals['subtotal']);
        $document->setAttribute('discount_amount', $totals['discount']);
        $document->setAttribute('tax_amount', $totals['tax']);
        $document->setAttribute('total', $totals['total']);
        $html = view('pdf.sales-document', [
            'document' => $document,
            'totals' => $totals,
            'typeLabel' => 'قالب فاتورة',
            'statusLabel' => $this->statusLabel($document->status),
        ])->render();
        $pdf = $this->renderSalesInvoice($html, $document->title);

        $this->activityLogger->record(
            $document,
            'sales_document.pdf_exported',
            $actor,
            after: ['number' => $document->number, 'type' => $document->type, 'format' => 'pdf'],
            request: $request,
        );

        return $this->download($pdf, 'cloudtech-'.$this->safeFilename($document->number).'.pdf');
    }

    public function projectSummary(Project $project, User $actor, Request $request): Response
    {
        $project->loadMissing([
            'client:id,name,code,email,phone',
            'manager:id,name,email',
            'status:id,label,semantic,color',
            'tasks' => fn ($query) => $query
                ->whereNull('archived_at')
                ->with(['status:id,label,semantic,color', 'assignee:id,name'])
                ->orderBy('due_at'),
            'requirements' => fn ($query) => $query
                ->whereNull('archived_at')
                ->with('status:id,label,semantic,color')
                ->orderBy('code'),
        ]);
        $activeTasks = $project->tasks->reject(
            fn ($task): bool => in_array($task->status->semantic, ['done', 'cancelled'], true),
        );
        $metrics = $this->projectMetrics->for($project);
        $html = view('pdf.project-summary', [
            'project' => $project,
            'activeTasks' => $activeTasks,
            'metrics' => $metrics,
        ])->render();
        $pdf = $this->render($html, 'ملخص مشروع '.$project->name);

        $this->activityLogger->record(
            $project,
            'project.summary_pdf_exported',
            $actor,
            after: [
                'code' => $project->code,
                'format' => 'pdf',
                'progress' => $metrics['progress'],
                'health' => $metrics['health'],
                'next_stage_id' => $metrics['next_stage']['id'] ?? null,
            ],
            request: $request,
        );

        return $this->download($pdf, 'project-'.$this->safeFilename($project->code).'-summary.pdf');
    }

    private function render(string $html, string $title, ?string $watermark = null): string
    {
        $tempDirectory = storage_path('app/private/pdf-tmp');
        $this->files->ensureDirectoryExists($tempDirectory, 0700);
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'tempDir' => $tempDirectory,
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 16,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor('CloudTech — Project Desk');
        $mpdf->SetCreator('Project Desk');
        if ($watermark !== null) {
            $mpdf->SetWatermarkText($watermark, 0.08);
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function renderSalesInvoice(string $html, string $title): string
    {
        $tempDirectory = storage_path('app/private/pdf-tmp');
        $this->files->ensureDirectoryExists($tempDirectory, 0700);
        $footer = null;
        if (preg_match('/<footer class="page-footer".*?<\/footer>/su', $html, $matches) === 1) {
            $footer = preg_replace(
                '/(<[^>]+data-region="invoice-page-number"[^>]*>).*?(<\/[^>]+>)/su',
                '$1{PAGENO} / {nbpg}$2',
                $matches[0],
            );
            $html = (string) preg_replace('/<footer class="page-footer".*?<\/footer>/su', '', $html);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'tempDir' => $tempDirectory,
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'margin_footer' => 7,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor('CloudTech — Project Desk');
        $mpdf->SetCreator('Project Desk');
        if (is_string($footer)) {
            $mpdf->SetHTMLFooter($footer);
        }
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function download(string $contents, string $filename): Response
    {
        $disposition = (new ResponseHeaderBag)->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        );

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }

    private function safeFilename(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value);

        return trim((string) $safe, '-') ?: 'document';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'مسودة',
            'archived' => 'مؤرشف',
            default => $status,
        };
    }
}

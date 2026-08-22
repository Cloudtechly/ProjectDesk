<?php

namespace App\Http\Controllers;

use App\Models\SalesDocument;
use App\Models\User;
use App\Services\PdfExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SalesDocumentPdfController extends Controller
{
    public function __invoke(
        Request $request,
        SalesDocument $salesDocument,
        PdfExportService $service,
    ): Response {
        abort_unless($salesDocument->isInvoiceTemplate(), 404);
        $this->authorize('view', $salesDocument);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $service->salesDocument($salesDocument, $user, $request);
    }
}

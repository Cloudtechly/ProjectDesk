<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\PdfExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProjectSummaryPdfController extends Controller
{
    public function __invoke(Request $request, Project $project, PdfExportService $service): Response
    {
        $this->authorize('view', $project);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $service->projectSummary($project, $user, $request);
    }
}

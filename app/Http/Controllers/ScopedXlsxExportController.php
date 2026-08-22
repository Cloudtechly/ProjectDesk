<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\XlsxExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ScopedXlsxExportController extends Controller
{
    public function __invoke(Request $request, string $resource, XlsxExportService $service): BinaryFileResponse
    {
        abort_unless(in_array($resource, ['clients', 'projects', 'tasks'], true), 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        Gate::forUser($user)->authorize('viewAny', $resource === 'clients' ? Client::class : Project::class);

        return $service->export($resource, $user, $request);
    }
}

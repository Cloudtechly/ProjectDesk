<?php

namespace App\Http\Controllers;

use App\Models\DataJob;
use App\Models\User;
use App\Services\CsvExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvController extends Controller
{
    public function template(string $resource, CsvExportService $service): StreamedResponse
    {
        Gate::authorize('create', DataJob::class);
        abort_unless(in_array($resource, ['clients', 'projects', 'tasks'], true), 404);

        return $service->template($resource);
    }

    public function export(Request $request, string $resource, CsvExportService $service): StreamedResponse
    {
        Gate::authorize('create', DataJob::class);
        abort_unless(in_array($resource, ['clients', 'projects', 'tasks'], true), 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $service->export($resource, $user);
    }
}

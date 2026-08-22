<?php

namespace App\Http\Controllers;

use App\Models\DataJob;
use App\Models\User;
use App\Services\XlsxExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class XlsxController extends Controller
{
    /** @var list<string> */
    private const RESOURCES = ['clients', 'projects', 'tasks'];

    public function template(string $resource, XlsxExportService $service): BinaryFileResponse
    {
        Gate::authorize('create', DataJob::class);
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        return $service->template($resource);
    }

    public function export(Request $request, string $resource, XlsxExportService $service): BinaryFileResponse
    {
        Gate::authorize('create', DataJob::class);
        abort_unless(in_array($resource, self::RESOURCES, true), 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $service->export($resource, $user, $request);
    }
}

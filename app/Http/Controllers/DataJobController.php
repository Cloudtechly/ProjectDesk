<?php

namespace App\Http\Controllers;

use App\Models\DataJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DataJob::class);
        $jobs = DataJob::query()
            ->with(['creator:id,name', 'fileObject:id,original_name,checksum_sha256,size_bytes'])
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->latest()
            ->paginate(min(max($request->integer('per_page', 30), 1), 100))
            ->withQueryString();

        return response()->json($jobs);
    }

    public function show(DataJob $dataJob): JsonResponse
    {
        $this->authorize('view', $dataJob);

        return response()->json(['data' => $dataJob->load(['creator:id,name', 'fileObject', 'importErrors'])]);
    }
}

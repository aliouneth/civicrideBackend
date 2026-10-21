<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CancellationReason;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformCancellationReasonController extends Controller
{
    public function index()
    {
        $reasons = CancellationReason::orderBy('sort_order')->orderBy('label')->paginate(50);

        return response()->json($reasons);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:cancellation_reasons,code', 'alpha_dash'],
            'label' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $reason = CancellationReason::create($data);

        return response()->json($reason, 201);
    }

    public function show(CancellationReason $cancellationReason)
    {
        return response()->json($cancellationReason);
    }

    public function update(Request $request, CancellationReason $cancellationReason)
    {
        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:64', 'unique:cancellation_reasons,code,' . $cancellationReason->id, 'alpha_dash'],
            'label' => ['sometimes', 'required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $cancellationReason->update($data);

        return response()->json($cancellationReason->fresh());
    }

    public function destroy(CancellationReason $cancellationReason)
    {
        // Prevent deleting the "other" fallback reason if it's the only one left
        $activeCount = CancellationReason::where('is_active', true)->count();
        if ($activeCount <= 1 && $cancellationReason->is_active) {
            return response()->json([
                'message' => 'Cannot delete the last active cancellation reason.',
            ], 422);
        }

        $cancellationReason->delete();

        return response()->json(null, 204);
    }
}
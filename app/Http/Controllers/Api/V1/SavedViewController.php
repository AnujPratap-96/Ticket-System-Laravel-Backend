<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A staff member's own named filter combinations for the ticket list ("My urgent", "Unassigned billing"…).
 */
class SavedViewController extends Controller
{
    private const FILTER_KEYS = ['status', 'priority', 'department_id', 'assigned_to', 'tag', 'search'];

    public function index(Request $request): JsonResponse
    {
        return response()->json(['views' => $request->user()->savedViews()->orderBy('name')->get(['id', 'name', 'filters'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:60', Rule::unique('saved_views', 'name')->where('user_id', $request->user()->id)],
            'filters' => ['required', 'array'],
            'filters.status' => ['nullable', 'in:open,in_progress,pending_customer,resolved,closed'],
            'filters.priority' => ['nullable', 'in:low,medium,high,urgent'],
            'filters.department_id' => ['nullable', 'integer'],
            'filters.assigned_to' => ['nullable', 'in:me'],
            'filters.tag' => ['nullable', 'string', 'max:30'],
            'filters.search' => ['nullable', 'string', 'max:100'],
        ]);

        $filters = array_filter(array_intersect_key($data['filters'], array_flip(self::FILTER_KEYS)), fn ($v) => $v !== null && $v !== '');
        abort_if($filters === [], 422, 'Choose at least one filter before saving a view.');
        abort_if($request->user()->savedViews()->count() >= 25, 422, 'You can keep up to 25 saved views.');

        $view = SavedView::create(['user_id' => $request->user()->id, 'name' => $data['name'], 'filters' => $filters]);

        return response()->json(['view' => $view->only(['id', 'name', 'filters'])], 201);
    }

    public function destroy(SavedView $view, Request $request): JsonResponse
    {
        abort_unless($view->user_id === $request->user()->id, 404);
        $view->delete();

        return response()->json(['message' => 'View deleted']);
    }
}

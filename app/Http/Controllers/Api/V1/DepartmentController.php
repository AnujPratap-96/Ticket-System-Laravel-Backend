<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\UserResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'departments' => DepartmentResource::collection(Department::orderBy('name')->get()),
        ]);
    }

    public function agents(Department $department, Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->role === UserRole::ADMIN
                || ($user->role->isStaff() && $user->department_id === $department->id),
            403,
            'Forbidden.'
        );

        $agents = $department->agents()->withCount(['assignedTickets as active_tickets_count' => function ($q) {
            $q->whereIn('status', [TicketStatus::OPEN->value, TicketStatus::IN_PROGRESS->value]);
        }])->get();

        return response()->json([
            'department' => new DepartmentResource($department),
            'agents' => UserResource::collection($agents),
        ]);
    }

    public function store(DepartmentRequest $request): JsonResponse
    {
        $department = Department::create($request->validated());

        return response()->json(['message' => 'Department created', 'department' => new DepartmentResource($department)], 201);
    }

    /**
     * Hours/timezone changes apply to tickets created afterwards; existing SLA deadlines are not rewritten.
     */
    public function update(Department $department, DepartmentRequest $request): JsonResponse
    {
        $department->update($request->validated());

        return response()->json(['message' => 'Department updated', 'department' => new DepartmentResource($department)]);
    }
}

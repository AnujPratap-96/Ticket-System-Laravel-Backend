<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CannedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CannedResponseController extends Controller
{
    /**
     * Personal + own department + global templates.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = CannedResponse::query()
            ->where(fn ($q) => $q
                ->where('owner_id', $user->id)
                ->orWhere(fn ($q) => $q->whereNull('owner_id')->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', $user->department_id ?? 0))))
            ->orderBy('title')
            ->get()
            ->map(fn ($r) => $this->present($r, $user));

        return response()->json(['canned_responses' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request);
        $scope = $this->scope($request, $user);

        $row = CannedResponse::create($data + $scope);

        return response()->json(['canned_response' => $this->present($row, $user)], 201);
    }

    public function update(CannedResponse $cannedResponse, Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canManage($cannedResponse, $user), 403, 'You cannot edit this template.');

        $cannedResponse->update($this->validated($request));

        return response()->json(['canned_response' => $this->present($cannedResponse, $user)]);
    }

    public function destroy(CannedResponse $cannedResponse, Request $request): JsonResponse
    {
        abort_unless($this->canManage($cannedResponse, $request->user()), 403, 'You cannot delete this template.');
        $cannedResponse->delete();

        return response()->json(['message' => 'Template deleted']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
    }

    /**
     * agent => personal; lead => personal or their department ("shared"); admin => personal, any department, or global.
     */
    private function scope(Request $request, $user): array
    {
        $request->validate(['scope' => ['nullable', 'in:personal,department,global']]);
        $scope = $request->input('scope', 'personal');

        return match (true) {
            $scope === 'personal' => ['owner_id' => $user->id, 'department_id' => null],
            $scope === 'department' && in_array($user->role, [UserRole::LEAD, UserRole::ADMIN], true) && $user->department_id => ['owner_id' => null, 'department_id' => $user->department_id],
            $scope === 'global' && $user->role === UserRole::ADMIN => ['owner_id' => null, 'department_id' => null],
            default => abort(403, 'You cannot create templates with that scope.'),
        };
    }

    private function canManage(CannedResponse $r, $user): bool
    {
        if ($r->owner_id) {
            return $r->owner_id === $user->id;
        }

        return $user->role === UserRole::ADMIN
            || ($user->role === UserRole::LEAD && $r->department_id !== null && $r->department_id === $user->department_id);
    }

    private function present(CannedResponse $r, $user): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'body' => $r->body,
            'scope' => $r->owner_id ? 'personal' : ($r->department_id ? 'department' : 'global'),
            'can_manage' => $this->canManage($r, $user),
        ];
    }
}

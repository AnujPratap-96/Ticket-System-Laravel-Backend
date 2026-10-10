<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateRoutingRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AccountEraser;
use App\Services\StaffInviteService;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    /**
     * Team roster. Admin sees everyone; a lead sees staff in their own department.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'role' => ['nullable', 'in:customer,agent,lead,admin'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = User::with('department', 'invite')->withCount(['assignedTickets as active_tickets_count' => fn ($q) => $q->whereIn('status', ['open', 'in_progress'])]);

        if ($user->role === UserRole::ADMIN) {
            $query->when($request->filled('role'), fn ($q) => $q->where('role', $request->query('role')), fn ($q) => $q->where('role', '!=', UserRole::CUSTOMER->value));
        } else {
            $query->whereIn('role', [UserRole::AGENT->value, UserRole::LEAD->value])->where('department_id', $user->department_id ?? 0);
        }

        if ($request->filled('search')) {
            $term = addcslashes($request->query('search'), '%_\\');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
        }

        return UserResource::collection($query->orderBy('name')->paginate((int) $request->query('per_page', 25)));
    }

    /**
     * Admin-only: provision staff (or customer) accounts.
     */
    public function store(StoreUserRequest $request, StaffInviteService $invites): JsonResponse
    {
        $invited = ! $request->filled('password');

        $user = User::create([
            'name' => $request->name,
            'email' => strtolower($request->email),
            // Invited people get an unusable random password until they accept and choose their own.
            'password' => $invited ? Str::random(64) : $request->password,
            'role' => UserRole::from($request->role),
            'organization_id' => $request->organization_id,
            'department_id' => $request->department_id,
            'max_active_tickets' => $request->integer('max_active_tickets', 10),
            'is_available_for_routing' => true,
            'email_verified_at' => $invited ? null : now(), // an invited person verifies by accepting the email link
        ]);

        if ($invited) {
            $invites->send($user, $request->user());
        }

        return response()->json([
            'message' => $invited ? "An invitation was emailed to {$user->email}." : 'User created successfully',
            'user' => new UserResource($user->load('department')),
        ], 201);
    }

    /**
     * Admin: delete or suspend a user account.
     */
    public function destroy(User $user, Request $request, AccountEraser $eraser): JsonResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'You cannot delete your own account.');
        abort_if(
            $user->role === UserRole::ADMIN && $user->is_active && User::where('role', UserRole::ADMIN->value)->where('is_active', true)->count() <= 1,
            422,
            'At least one active admin is required.'
        );

        $name = $user->name;
        $eraser->erase($user);

        return response()->json(['message' => "{$name} has been removed and suspended."]);
    }

    public function erase(User $user, Request $request, AccountEraser $eraser): JsonResponse
    {
        $data = $request->validate(['confirm_email' => ['required', 'string']]);

        abort_if($user->id === $request->user()->id, 422, 'You cannot delete your own account here. Use My account instead.');
        abort_unless(strcasecmp($data['confirm_email'], $user->email) === 0, 422, 'The email you typed does not match this account.');
        abort_if(! $user->is_active, 422, 'This account is already suspended.');
        abort_if(
            $user->role === UserRole::ADMIN && $user->is_active && User::where('role', UserRole::ADMIN->value)->where('is_active', true)->count() <= 1,
            422,
            'At least one active admin is required.'
        );

        $name = $user->name;
        $eraser->erase($user);

        return response()->json(['message' => "{$name} has been removed and suspended."]);
    }

    public function resendInvite(User $user, Request $request, StaffInviteService $invites): JsonResponse
    {
        abort_unless($user->invite && $user->invite->accepted_at === null, 422, 'This person has already accepted their invitation.');

        $invites->send($user, $request->user());

        return response()->json(['message' => "A new invitation was emailed to {$user->email}."]);
    }

    public function update(User $user, UpdateUserRequest $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validated();

        // Prevent an admin from locking themselves out, and never leave the system without an admin.
        $demotes = isset($data['role']) && $data['role'] !== UserRole::ADMIN->value;
        $deactivates = array_key_exists('is_active', $data) && ! $data['is_active'];

        if ($user->id === $actor->id && ($demotes || $deactivates)) {
            abort(422, 'You cannot demote or deactivate your own account.');
        }
        if ($user->role === UserRole::ADMIN && ($demotes || $deactivates)
            && User::where('role', UserRole::ADMIN->value)->where('is_active', true)->count() <= 1) {
            abort(422, 'At least one active admin is required.');
        }

        $newRole = $data['role'] ?? $user->role->value;
        $newDept = array_key_exists('department_id', $data) ? $data['department_id'] : $user->department_id;
        if (in_array($newRole, ['agent', 'lead'], true) && ! $newDept) {
            abort(422, 'Agents and leads must belong to a department.');
        }

        $user->update($data);

        // Deactivation or a role change ends every existing session.
        if ($deactivates || $demotes || (isset($data['role']) && $data['role'] !== $user->getOriginal('role'))) {
            $user->tokens()->delete();
        }

        return response()->json(['message' => 'User updated', 'user' => new UserResource($user->fresh('department'))]);
    }

    /**
     * Lead/admin: tune routing for an agent. Leads are limited to their own department.
     */
    public function updateRouting(User $user, UpdateRoutingRequest $request): JsonResponse
    {
        $actor = $request->user();

        abort_unless($user->role->isStaff() && $user->role !== UserRole::ADMIN, 422, 'Routing applies to agents and leads only.');
        abort_unless(
            $actor->role === UserRole::ADMIN || ($actor->department_id !== null && $actor->department_id === $user->department_id),
            403,
            'Forbidden. This agent is not in your department.'
        );

        $user->update($request->validated());

        return response()->json(['message' => 'Routing updated', 'user' => new UserResource($user->fresh('department'))]);
    }
}

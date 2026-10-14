<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use App\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The platform console: create, rename and suspend workspaces. It only ever counts things (people, tickets); it can
 * never list or open a workspace's tickets or customers.
 */
class PlatformTenantController extends Controller
{
    public function index(): JsonResponse
    {
        // plain aggregate queries (counts only): the platform owner never loads a workspace's people or tickets as records
        $users = DB::table('users')->whereNotNull('tenant_id')->selectRaw('tenant_id, role, count(*) as n')->groupBy('tenant_id', 'role')->get()->groupBy('tenant_id');
        $tickets = DB::table('tickets')->selectRaw('tenant_id, count(*) as total, sum(case when status not in (?, ?) then 1 else 0 end) as open_count, sum(case when created_at >= ? then 1 else 0 end) as this_month', [TicketStatus::RESOLVED->value, TicketStatus::CLOSED->value, now()->startOfMonth()])
            ->groupBy('tenant_id')->get()->keyBy('tenant_id');

        return response()->json(['tenants' => Tenant::orderBy('id')->get()->map(function (Tenant $t) use ($users, $tickets) {
            $byRole = ($users[$t->id] ?? collect())->pluck('n', 'role');

            return [
                'id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'is_active' => $t->is_active, 'created_at' => $t->created_at->toISOString(), 'url' => $t->url('/login'),
                'staff' => (int) (($byRole['admin'] ?? 0) + ($byRole['lead'] ?? 0) + ($byRole['agent'] ?? 0)),
                'customers' => (int) ($byRole['customer'] ?? 0),
                'tickets' => (int) ($tickets[$t->id]->total ?? 0),
                'open_tickets' => (int) ($tickets[$t->id]->open_count ?? 0),
                'tickets_this_month' => (int) ($tickets[$t->id]->this_month ?? 0),
            ];
        })]);
    }

    public function store(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['nullable', 'string', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/'],
            'admin_name' => ['required', 'string', 'min:2', 'max:100'],
            'admin_email' => ['required', 'email', 'max:255'],
        ]);

        $slug = $data['slug'] ?? $provisioner->suggestSlug($data['name']);
        abort_if($provisioner->taken($slug), 422, 'That workspace address is already taken.');

        $tenant = $provisioner->create($data['name'], $slug, $data['admin_name'], $data['admin_email'], null, $request->user());

        return response()->json(['message' => "Workspace created. An invitation was emailed to {$data['admin_email']}.", 'tenant' => ['id' => $tenant->id, 'slug' => $tenant->slug, 'url' => $tenant->url('/login')]], 201);
    }

    public function update(Tenant $tenant, Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'min:2', 'max:120'], 'is_active' => ['sometimes', 'boolean']]);

        if (array_key_exists('is_active', $data)) {
            $data['suspended_at'] = $data['is_active'] ? null : now();
        }
        $tenant->update($data);

        return response()->json(['message' => $tenant->is_active ? 'Workspace is active.' : 'Workspace suspended: nobody in it can sign in or use the API.', 'tenant' => $tenant]);
    }

    /** Permanent. The owner must type the workspace address to confirm. */
    public function destroy(Tenant $tenant, Request $request, \App\Services\TenantEraser $eraser): JsonResponse
    {
        $request->validate(['confirm_slug' => ['required', 'string']]);
        abort_unless(hash_equals($tenant->slug, (string) $request->input('confirm_slug')), 422, 'Type the workspace address exactly to confirm deletion.');

        $eraser->erase($tenant);

        return response()->json(['message' => 'Workspace and all its data were permanently deleted.']);
    }

    public function health(\App\Services\OpsHealth $health): JsonResponse
    {
        return response()->json($health->report());
    }
}

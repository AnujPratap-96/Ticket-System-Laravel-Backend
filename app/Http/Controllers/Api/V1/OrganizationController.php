<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationRequest;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer companies. An organization decides which SLA plan its customers get, and customers join one
 * automatically when they sign up with an email on its domain. Admin only.
 */
class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $orgs = Organization::query()
            ->withCount(['users as customers_count' => fn ($q) => $q->where('role', UserRole::CUSTOMER->value), 'tickets'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = '%'.addcslashes($request->query('search'), '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('domain', 'like', $like));
            })
            ->orderBy('name')->get();

        return response()->json(['organizations' => $orgs->map(fn ($o) => $this->present($o))]);
    }

    public function store(OrganizationRequest $request): JsonResponse
    {
        $org = Organization::create($request->validated() + ['is_active' => true]);

        return response()->json(['message' => 'Organization created', 'organization' => $this->present($org)], 201);
    }

    public function update(Organization $organization, OrganizationRequest $request): JsonResponse
    {
        $organization->update($request->validated());

        return response()->json(['message' => 'Organization updated', 'organization' => $this->present($organization->loadCount(['users as customers_count' => fn ($q) => $q->where('role', 'customer'), 'tickets']))]);
    }

    /**
     * Customers who signed up before this organization existed: link everyone whose email is on its domain
     * (and who has no organization yet).
     */
    public function attachCustomers(Organization $organization): JsonResponse
    {
        $count = User::where('role', UserRole::CUSTOMER->value)
            ->whereNull('organization_id')
            ->where('email', 'like', '%@'.addcslashes($organization->domain, '%_\\'))
            ->update(['organization_id' => $organization->id]);

        return response()->json(['message' => $count === 1 ? '1 customer was added.' : "{$count} customers were added.", 'attached' => $count]);
    }

    public function customers(Organization $organization): JsonResponse
    {
        $users = $organization->users()->where('role', UserRole::CUSTOMER->value)->orderBy('name')->get(['id', 'name', 'email', 'is_org_admin', 'is_active']);

        return response()->json(['customers' => $users]);
    }

    /**
     * Make a customer the "company admin": they can then see and reply to every ticket from their colleagues.
     */
    public function setCompanyAdmin(Organization $organization, User $user, Request $request): JsonResponse
    {
        $data = $request->validate(['is_org_admin' => ['required', 'boolean']]);
        abort_unless($user->organization_id === $organization->id && $user->role === UserRole::CUSTOMER, 422, 'That person is not a customer of this organization.');

        $user->update(['is_org_admin' => $data['is_org_admin']]);

        return response()->json(['message' => $data['is_org_admin'] ? "{$user->name} can now see the whole company's tickets." : "{$user->name} now sees only their own tickets.", 'customer' => $user->only(['id', 'name', 'email', 'is_org_admin', 'is_active'])]);
    }

    public function destroy(Organization $organization): JsonResponse
    {
        User::where('organization_id', $organization->id)->update([
            'organization_id' => null,
            'is_org_admin' => false,
        ]);
        Ticket::where('organization_id', $organization->id)->update([
            'organization_id' => null,
        ]);

        $name = $organization->name;
        $organization->delete();

        return response()->json(['message' => "Organization {$name} was deleted successfully."]);
    }

    private function present(Organization $o): array
    {
        return [
            'id' => $o->id,
            'name' => $o->name,
            'domain' => $o->domain,
            'sla_tier' => $o->sla_tier->value,
            'is_active' => (bool) $o->is_active,
            'customers_count' => (int) ($o->customers_count ?? 0),
            'tickets_count' => (int) ($o->tickets_count ?? 0),
        ];
    }
}

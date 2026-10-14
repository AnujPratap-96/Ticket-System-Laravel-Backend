<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationRequest;
use App\Models\Organization;
use App\Models\Ticket;
use App\Services\PlanLimits;
use App\Services\SlaCalculatorService;
use Illuminate\Support\Facades\DB;
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
    public function attachCustomers(Organization $organization, PlanLimits $limits): JsonResponse
    {
        $candidates = User::where('role', UserRole::CUSTOMER->value)
            ->whereNull('organization_id')
            ->where('email', 'like', '%@'.addcslashes($organization->domain, '%_\\'));

        // Never beyond the plan's seat limit.
        $left = $limits->seatsLeft($organization);
        $ids = $left === null ? $candidates->pluck('id') : $candidates->orderBy('id')->limit($left)->pluck('id');
        $count = User::whereIn('id', $ids)->update(['organization_id' => $organization->id]);

        $remaining = User::where('role', UserRole::CUSTOMER->value)->whereNull('organization_id')->where('email', 'like', '%@'.addcslashes($organization->domain, '%_\\'))->count();
        $msg = $count === 1 ? '1 customer was added.' : "{$count} customers were added.";
        if ($remaining > 0) {
            $msg .= " {$remaining} more could not be added because the plan's user limit was reached.";
        }

        return response()->json(['message' => $msg, 'attached' => $count, 'not_added' => $remaining]);
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

    /**
     * Delete a company. Its people are unlinked (they become independent customers). Tickets must always belong to a
     * company, so if it has any the admin must say where they go (?move_to=<organization id>); they then follow that
     * company's plan from now on (open SLA clocks are recalculated).
     */
    public function destroy(Organization $organization, Request $request, SlaCalculatorService $sla): JsonResponse
    {
        $data = $request->validate(['move_to' => ['nullable', 'integer', \App\Tenancy\TenantRule::exists('organizations')]]);
        $count = $organization->tickets()->count();
        $target = null;

        if ($count > 0) {
            abort_if(empty($data['move_to']), 422, "This organization has {$count} ".($count === 1 ? 'ticket' : 'tickets').'. Choose another organization to move them to, then delete it.');
            abort_if((int) $data['move_to'] === $organization->id, 422, 'Choose a different organization.');
            $target = Organization::findOrFail($data['move_to']);
        }

        $name = $organization->name;

        DB::transaction(function () use ($organization, $target, $sla) {
            User::where('organization_id', $organization->id)->update(['organization_id' => null, 'is_org_admin' => false]);

            if ($target) {
                $organization->tickets()->update(['organization_id' => $target->id]);
                Ticket::where('organization_id', $target->id)->whereNotIn('status', ['resolved', 'closed'])
                    ->with(['organization', 'department'])->get()->each(fn (Ticket $t) => $sla->attachDeadlines($t));
            }

            $organization->delete();
        });

        return response()->json(['message' => $count ? "{$name} was deleted and its {$count} ".($count === 1 ? 'ticket was' : 'tickets were')." moved to {$target->name}." : "{$name} was deleted."]);
    }

    private function present(Organization $o): array
    {
        $limits = app(PlanLimits::class);
        $usage = $limits->usage($o);

        return [
            'id' => $o->id,
            'name' => $o->name,
            'domain' => $o->domain,
            'sla_tier' => $o->sla_tier->value,
            'is_active' => (bool) $o->is_active,
            'customers_count' => (int) ($o->customers_count ?? $usage['customers']),
            'tickets_count' => (int) ($o->tickets_count ?? 0),
            // The raw overrides (null = plan default) for the form, and what actually applies for the usage bars.
            'overrides' => ['monthly_ticket_limit' => $o->monthly_ticket_limit, 'max_customers' => $o->max_customers, 'max_open_per_customer' => $o->max_open_per_customer],
            'limits' => $limits->for($o),
            'usage' => $usage,
        ];
    }
}

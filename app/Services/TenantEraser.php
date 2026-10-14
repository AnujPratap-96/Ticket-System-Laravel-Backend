<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Permanently removes one workspace and everything in it. The order matters: tickets -> organizations and
 * tickets -> customers are RESTRICT foreign keys, so tickets (and what cascades from them) must go first.
 */
class TenantEraser
{
    public function erase(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant) {
            $id = $tenant->id;
            $userIds = DB::table('users')->where('tenant_id', $id)->pluck('id');

            DB::table('tickets')->where('tenant_id', $id)->delete();   // messages, audits, ratings, SLA clocks cascade
            DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->whereIn('tokenable_id', $userIds)->delete();
            DB::table('users')->where('tenant_id', $id)->delete();
            DB::table('organizations')->where('tenant_id', $id)->delete();
            DB::table('departments')->where('tenant_id', $id)->delete();
            $tenant->delete();                                          // everything else carries ON DELETE CASCADE on tenant_id
        });
    }
}

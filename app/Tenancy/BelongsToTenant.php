<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Put this on every model whose rows belong to one workspace. It
 *  - filters every query to the current workspace (TenantScope), so another workspace's rows cannot even be found,
 *  - stamps new rows with the current workspace, or, for ticket children created in background work, with their ticket's.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if ($model->tenant_id) {
                return;
            }

            $model->tenant_id = TenantContext::id()
                ?? (isset($model->ticket_id) ? Ticket::withoutGlobalScopes()->whereKey($model->ticket_id)->value('tenant_id') : null);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

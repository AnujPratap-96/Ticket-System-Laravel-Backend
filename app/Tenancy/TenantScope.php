<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Adds "AND tenant_id = <current workspace>" to every query on a tenant-owned model. */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($id = TenantContext::id()) {
            $builder->where($model->qualifyColumn('tenant_id'), $id);
        }
    }
}

<?php

namespace App\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation rules limited to the current workspace. Laravel's plain `exists:` / `unique:` rules go straight to the
 * database and ignore model scopes, so using them here would let someone reference (or collide with) another workspace.
 */
class TenantRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('tenant_id', TenantContext::id());
    }

    public static function unique(string $table, string $column): Unique
    {
        return Rule::unique($table, $column)->where('tenant_id', TenantContext::id());
    }
}

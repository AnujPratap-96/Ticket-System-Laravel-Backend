<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy. A tenant is one independent support company (its own staff, customers, tickets, plans, articles...).
 * Everything that belongs to a tenant gets a tenant_id; everything that used to be unique "globally" becomes unique
 * per tenant. Existing data is moved into a first tenant, so nothing is lost.
 */
return new class extends Migration
{
    /** table => [old unique columns to drop, new unique columns] */
    private array $uniques = [
        'users' => [['email'], ['tenant_id', 'email']],
        'organizations' => [['domain'], ['tenant_id', 'domain']],
        'departments' => [['slug'], ['tenant_id', 'slug']],
        'sla_policies' => [['tier', 'priority'], ['tenant_id', 'tier', 'priority']],
        'business_holidays' => [['holiday_date'], ['tenant_id', 'holiday_date']],
        'tags' => [['name'], ['tenant_id', 'name']],
        'articles' => [['slug'], ['tenant_id', 'slug']],
    ];

    /** Tables whose rows always belong to exactly one tenant (users may also be platform staff with no tenant). */
    private array $scoped = [
        'organizations', 'departments', 'tickets', 'ticket_messages', 'ticket_audits', 'ticket_ratings', 'ticket_sla_deadlines',
        'sla_policies', 'business_holidays', 'articles', 'canned_responses', 'tags', 'automation_rules', 'webhook_endpoints',
    ];

    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 60)->unique();          // the workspace address: acme-support  ->  acme-support.yourdomain.com
            $table->boolean('is_active')->default(true);
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'Default workspace', 'slug' => 'default', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 1. add the column (nullable first), 2. fill it for existing rows
        foreach (array_merge($this->scoped, ['users']) as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            });
            DB::table($t)->update(['tenant_id' => $tenantId]);
        }

        // 3. now it is required (except users: platform staff belong to no tenant), indexed and a real foreign key
        foreach ($this->scoped as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
            });
        }
        foreach (array_merge($this->scoped, ['users']) as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
        }

        // "unique" now means "unique inside one tenant"
        foreach ($this->uniques as $table => [$old, $new]) {
            Schema::table($table, function (Blueprint $t) use ($old, $new) {
                $t->dropUnique($old);
                $t->unique($new);
            });
        }

        // Settings: the key is only unique within a tenant, so the table needs a real id.
        $rows = DB::table('app_settings')->get();
        Schema::drop('app_settings');
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });
        foreach ($rows as $r) {
            DB::table('app_settings')->insert(['tenant_id' => $tenantId, 'key' => $r->key, 'value' => $r->value, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('kb_searches', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->index('tenant_id');
        });
        DB::table('kb_searches')->update(['tenant_id' => $tenantId]);
    }

    public function down(): void
    {
        throw new RuntimeException('Merging tenants back into one is not supported. Restore from a backup instead.');
    }
};

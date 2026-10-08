<?php

namespace App\Models;

use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number',
        'organization_id',
        'customer_id',
        'assigned_agent_id',
        'merged_into_id',
        'department_id',
        'title',
        'description',
        'attachments_json',
        'custom_fields',
        'ai_triage',
        'sentiment',
        'is_ai_urgent',
        'status',
        'priority',
        'channel',
        'first_responded_at',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'attachments_json' => 'array',
        'custom_fields' => 'array',
        'ai_triage' => 'array',
        'is_ai_urgent' => 'boolean',
        'status' => TicketStatus::class,
        'priority' => TicketPriority::class,
        'channel' => TicketChannel::class,
        'first_responded_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * The single definition of "which tickets may this person see". Mirrors TicketPolicy::view().
     *
     *   admin    everything
     *   lead     their department (plus tickets they were mentioned on / watch)
     *   agent    tickets assigned to them, the unassigned pool of their department (so they can claim),
     *            and tickets they were mentioned on / watch
     *   customer their own tickets; a company admin also sees their whole organization
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $watching = fn ($w) => $w->orWhereHas('watchers', fn ($x) => $x->where('users.id', $user->id));

        return match ($user->role) {
            UserRole::ADMIN => $query,
            UserRole::CUSTOMER => $query->where(fn ($w) => $w->where('customer_id', $user->id)
                ->when($user->is_org_admin && $user->organization_id, fn ($x) => $x->orWhere('organization_id', $user->organization_id))),
            UserRole::LEAD => $query->where(fn ($w) => $watching($w->where('department_id', $user->department_id ?? 0))),
            UserRole::AGENT => $query->where(fn ($w) => $watching($w->where('assigned_agent_id', $user->id)
                ->orWhere(fn ($pool) => $pool->whereNull('assigned_agent_id')->where('department_id', $user->department_id ?? 0)))),
        };
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at', 'asc');
    }

    public function slaDeadlines(): HasMany
    {
        return $this->hasMany(TicketSlaDeadline::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(TicketAudit::class)->orderBy('created_at', 'desc');
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_watchers')->withTimestamps();
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function rating(): HasOne
    {
        return $this->hasOne(TicketRating::class);
    }
}

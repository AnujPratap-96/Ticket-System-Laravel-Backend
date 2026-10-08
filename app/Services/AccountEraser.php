<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\CannedResponse;
use App\Models\StaffInvite;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketRating;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Right to erasure. The user row stays (tickets, messages and the audit trail point at it) but the person is gone:
 * identity anonymised, sessions/2FA/photo removed, sign-in impossible.
 *
 *   customers: their own free text (messages, ticket descriptions, rating comments) is removed as well.
 *   staff:     replies they wrote stay (they are the company's own records); their open tickets are released.
 */
class AccountEraser
{
    public function __construct(private CloudinaryService $cloudinary) {}

    public function erase(User $user): void
    {
        $avatar = $user->avatar_public_id;
        $isCustomer = $user->role === UserRole::CUSTOMER;

        DB::transaction(function () use ($user, $isCustomer) {
            if ($isCustomer) {
                TicketMessage::where('sender_id', $user->id)->update(['body' => '[removed at the customer\'s request]', 'attachments_json' => null]);
                Ticket::where('customer_id', $user->id)->update(['description' => '[removed at the customer\'s request]', 'attachments_json' => null]);
                TicketRating::where('customer_id', $user->id)->update(['comment' => null]);
            } else {
                Ticket::where('assigned_agent_id', $user->id)->update(['assigned_agent_id' => null]);   // back to the pool
            }

            DB::table('ticket_watchers')->where('user_id', $user->id)->delete();
            CannedResponse::where('owner_id', $user->id)->delete();
            StaffInvite::where('user_id', $user->id)->delete();
            $user->notifications()->delete();
            $user->tokens()->delete();

            $user->forceFill([
                'name' => 'Deleted user',
                'email' => "deleted-{$user->id}@deleted.invalid",
                'password' => Str::random(40),
                'is_active' => false,
                'is_org_admin' => false,
                'is_available_for_routing' => false,
                'avatar_public_id' => null,
                'organization_id' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
        });

        // Remote clean-up last: a Cloudinary hiccup must not undo the erasure.
        if ($avatar && $this->cloudinary->enabled()) {
            $this->cloudinary->delete($avatar, 'image', 'upload');
        }
    }
}

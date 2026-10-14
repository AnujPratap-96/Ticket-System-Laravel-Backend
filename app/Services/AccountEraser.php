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
 * Two different things happen to an account:
 *
 *  - erase():  the person's right to erasure (a customer erasing their own account, or an admin doing it on request).
 *              The user row stays because tickets, messages and the audit trail point at it, but the person is gone:
 *              identity anonymised, sessions/2FA/photo removed, their own free text deleted (customers) and sign-in impossible.
 *
 *  - remove(): an admin removing someone from the system (an employee who left, a closed customer).
 *              With no history the account is deleted outright; otherwise it is suspended and keeps its real name and email
 *              so old tickets still read correctly, and an admin can reactivate it.
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
            }

            $this->releaseAndSignOut($user);

            $user->forceFill([
                'name' => 'Deleted user',
                'job_title' => null,
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

        $this->deleteAvatar($avatar);
    }

    /** @return string 'deleted' when nothing referenced the account, 'suspended' when its history was kept */
    public function remove(User $user): string
    {
        $avatar = $user->avatar_public_id;

        $result = DB::transaction(function () use ($user) {
            $this->releaseAndSignOut($user);

            $hasHistory = Ticket::where('customer_id', $user->id)->orWhere('assigned_agent_id', $user->id)->exists()
                || TicketMessage::where('sender_id', $user->id)->exists()
                || DB::table('ticket_audits')->where('actor_id', $user->id)->exists();

            if (! $hasHistory) {
                $user->delete();

                return 'deleted';
            }

            $user->forceFill([
                'password' => Str::random(40),
                'is_active' => false,
                'is_org_admin' => false,
                'is_available_for_routing' => false,
                'avatar_public_id' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            return 'suspended';
        });

        $this->deleteAvatar($avatar);

        return $result;
    }

    private function releaseAndSignOut(User $user): void
    {
        if ($user->role !== UserRole::CUSTOMER) {
            Ticket::where('assigned_agent_id', $user->id)->update(['assigned_agent_id' => null]);   // back to the pool
        }

        DB::table('ticket_watchers')->where('user_id', $user->id)->delete();
        DB::table('push_subscriptions')->where('user_id', $user->id)->delete();
        CannedResponse::where('owner_id', $user->id)->delete();
        StaffInvite::where('user_id', $user->id)->delete();
        $user->notifications()->delete();
        $user->tokens()->delete();
    }

    /** Remote clean-up last: a Cloudinary hiccup must not undo the change. */
    private function deleteAvatar(?string $publicId): void
    {
        if ($publicId && $this->cloudinary->enabled()) {
            $this->cloudinary->delete($publicId, 'image', 'upload');
        }
    }
}

<?php

namespace App\Services;

use App\Models\StaffInvite;
use App\Models\User;
use App\Notifications\StaffInviteNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Staff accounts are created by an admin, but the person chooses their own password:
 * they get an email link carrying a single-use token (stored only as a hash, valid for 7 days).
 */
class StaffInviteService
{
    public const TTL_DAYS = 7;

    public function send(User $user, ?User $invitedBy): void
    {
        $token = Str::random(48);

        StaffInvite::updateOrCreate(['user_id' => $user->id], [
            'invited_by' => $invitedBy?->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::TTL_DAYS),
            'accepted_at' => null,
        ]);

        $user->loadMissing('department');
        Notification::route('mail', $user->email)->notify(new StaffInviteNotification(
            name: $user->name,
            email: $user->email,
            role: $user->role->value,
            department: $user->department?->name,
            invitedBy: $invitedBy?->name,
            token: $token,
            days: self::TTL_DAYS,
        ));
    }

    /**
     * Returns the pending invite for this email+token, or null if the link is wrong, used or expired.
     */
    public function find(string $email, string $token): ?StaffInvite
    {
        $user = User::where('email', strtolower($email))->first();
        $invite = $user?->invite;

        if (! $invite || ! $invite->isPending() || ! hash_equals($invite->token_hash, hash('sha256', $token))) {
            return null;
        }

        return $invite->setRelation('user', $user);
    }
}

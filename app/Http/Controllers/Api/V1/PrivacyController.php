<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\AccountEraser;
use App\Models\TicketMessage;
use App\Models\TicketRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PrivacyController extends Controller
{
    /**
     * Everything we hold about the signed-in user (GDPR access / portability).
     */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'exported_at' => now()->toISOString(),
            'profile' => $user->only(['id', 'name', 'email', 'role', 'organization_id', 'department_id', 'email_verified_at', 'created_at']),
            'tickets' => Ticket::where('customer_id', $user->id)->get(['id', 'ticket_number', 'title', 'description', 'status', 'priority', 'created_at', 'resolved_at']),
            'messages' => TicketMessage::where('sender_id', $user->id)->where('is_internal_note', false)->get(['id', 'ticket_id', 'body', 'created_at']),
            'ratings' => TicketRating::where('customer_id', $user->id)->get(['ticket_id', 'rating', 'comment', 'created_at']),
            'notifications' => $user->notifications()->get(['id', 'data', 'created_at']),
        ]);
    }

    /**
     * Erase a customer account. Tickets stay (the support record belongs to the business) but
     * the person is anonymised and their own free text is removed. Staff accounts are retired
     * by an admin instead, because audit rows reference them.
     */
    public function erase(Request $request, AccountEraser $eraser): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === UserRole::CUSTOMER, 403, 'Staff accounts must be deactivated by an administrator.');
        $request->validate(['password' => ['required', 'string']]);
        abort_unless(Hash::check($request->password, $user->password), 422, 'Password is incorrect.');

        $eraser->erase($user);

        return response()->json(['message' => 'Your account has been erased.']);
    }
}

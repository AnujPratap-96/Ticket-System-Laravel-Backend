<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\InboundEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboundEmailController extends Controller
{
    /**
     * Provider webhook (Postmark inbound JSON, or a generic {from, subject, text} payload).
     * Authenticated by a shared secret; it is disabled until one is configured.
     */
    public function receive(Request $request, InboundEmailService $service): JsonResponse
    {
        $secret = (string) config('services.inbound_email.secret');
        $given = (string) ($request->header('X-Webhook-Secret') ?? $request->query('secret', ''));

        abort_if($secret === '', 503, 'Inbound email is not configured.');
        abort_unless(hash_equals($secret, $given), 401, 'Invalid webhook secret.');

        $from = $request->input('FromFull.Email') ?? $request->input('From') ?? $request->input('from');
        // "Name <addr@x>" -> addr@x
        if (is_string($from) && preg_match('/<([^>]+)>/', $from, $m)) {
            $from = $m[1];
        }

        if (! is_string($from) || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['result' => 'invalid_sender'], 202);
        }

        $headers = collect($request->input('Headers', []));
        $spf = (string) ($headers->firstWhere('Name', 'Received-SPF')['Value'] ?? '');

        $result = $service->handle([
            'from' => $from,
            'subject' => (string) ($request->input('Subject') ?? $request->input('subject') ?? ''),
            'body' => (string) ($request->input('StrippedTextReply') ?: $request->input('TextBody') ?: $request->input('text') ?: ''),
            'message_id' => $request->input('MessageID') ?? $request->input('message_id'),
            'spf_failed' => (bool) preg_match('/^\s*fail/i', $spf),
        ]);

        // Always 200/202 so the provider does not retry mail we deliberately dropped.
        return response()->json($result, 202);
    }
}

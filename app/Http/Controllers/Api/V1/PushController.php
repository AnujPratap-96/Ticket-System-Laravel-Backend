<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Notifications\Channels\WebPushChannel;
use App\Support\DeviceLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    /** The browser needs the public key to subscribe. Null = push is not set up on this server. */
    public function key(): JsonResponse
    {
        return response()->json(['public_key' => WebPushChannel::configured() ? config('services.webpush.public_key') : null]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        abort_unless(WebPushChannel::configured(), 503, 'Push notifications are not set up on this server.');

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', 'url'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
        ]);
        abort_unless(WebPushChannel::endpointAllowed($data['endpoint']), 422, 'That push address is not supported.');

        $user = $request->user();
        abort_if($user->pushSubscriptions()->count() >= 10 && ! $user->pushSubscriptions()->where('endpoint_hash', hash('sha256', $data['endpoint']))->exists(), 422, 'This account already has the maximum number of subscribed devices.');

        // The same browser re-subscribing (or a different person signing in on it) just takes over the row.
        PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $user->id,
            'endpoint' => $data['endpoint'],
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
            'device' => DeviceLabel::for($request->userAgent(), null),
        ]);

        return response()->json(['message' => 'Notifications are on for this device.'], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        $request->user()->pushSubscriptions()->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->json(['message' => 'Notifications are off for this device.']);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * "Cron by URL": an external pinger calls this once a minute instead of Render running a Cron Job
 * and a Background Worker. Each call
 *   1. runs the Laravel scheduler (flags SLA breaches), and
 *   2. drains the queue for up to ~25 s (emails, SLA warnings), then returns.
 *
 * Protected by a shared secret in the X-Cron-Secret header (never in the URL, which would end up in logs).
 * Disabled until CRON_SECRET is configured.
 */
class InternalTickController extends Controller
{
    private const QUEUE_SECONDS = 25;
    private const MAX_JOBS = 50;

    public function tick(Request $request): JsonResponse
    {
        $secret = (string) config('services.cron.secret');

        abort_if($secret === '', 503, 'The scheduler endpoint is not enabled.');
        abort_unless(hash_equals($secret, (string) $request->header('X-Cron-Secret')), 401, 'Invalid secret.');

        // Overlapping ticks (a pinger retry) must not pile up.
        $lock = Cache::lock('internal-tick', 55);
        if (! $lock->get()) {
            return response()->json(['status' => 'busy'], 202);
        }

        try {
            Artisan::call('schedule:run');
            $scheduler = trim(Artisan::output());

            $queue = 'skipped (sync queue driver)';
            if (config('queue.default') !== 'sync') {
                Artisan::call('queue:work', [
                    '--stop-when-empty' => true,
                    '--max-time' => self::QUEUE_SECONDS,
                    '--max-jobs' => self::MAX_JOBS,
                    '--tries' => 3,
                ]);
                $queue = 'drained';
            }

            return response()->json(['status' => 'ok', 'queue' => $queue, 'scheduler' => $scheduler !== '' ? 'ran' : 'nothing due']);
        } finally {
            $lock->release();
        }
    }
}

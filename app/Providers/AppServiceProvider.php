<?php

namespace App\Providers;

use App\Models\Ticket;
use App\Policies\TicketPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request so a rule's own changes can never re-trigger rules.
        $this->app->singleton(\App\Services\AutomationEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Ticket::class, TicketPolicy::class);

        // Every in-app notification also nudges the recipient's open browser so the bell updates instantly.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationSent::class, function ($e) {
            if ($e->channel === 'database' && $e->notifiable instanceof \App\Models\User) {
                try {
                    event(new \App\Events\UserPinged($e->notifiable->id));
                } catch (\Throwable) {
                    // realtime is a bonus; never fail a notification because of it
                }
            }
        });

        RateLimiter::for('otp', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // AI assistant: guests are throttled hard per IP; signed-in users per account.
        RateLimiter::for('assistant', function (Request $request) {
            $user = $request->user('sanctum');

            return $user
                ? [Limit::perMinute(12)->by('assistant:u:'.$user->id), Limit::perDay(150)->by('assistant:u:'.$user->id)]
                : [Limit::perMinute(4)->by('assistant:ip:'.$request->ip()), Limit::perDay(25)->by('assistant:ip:'.$request->ip())];
        });
        RateLimiter::for('ai-staff', fn (Request $request) => [Limit::perMinute(10)->by('ai:u:'.$request->user()?->id), Limit::perHour(60)->by('ai:u:'.$request->user()?->id)]);

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip().'|'.strtolower((string) $request->input('email'))));
    }
}

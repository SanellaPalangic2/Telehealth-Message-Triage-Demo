<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimits();
    }

    /**
     * Throttled requests are sent back to the page with a clear message
     * instead of a bare 429 error page.
     */
    private function configureRateLimits(): void
    {
        // Read limits per request (inside the closures), not once at boot.
        $limits = fn (string $key) => config("triage.rate_limits.{$key}");

        $backWith = fn (string $message) => fn (Request $request) => $request->route('message')
            ? to_route('messages.show', $request->route('message'))->with('error', $message)
            : to_route('inbox')->with('error', $message);

        RateLimiter::for('ai-triage', fn (Request $request) => [
            Limit::perMinute($limits('ai_per_minute_per_ip'))
                ->by('ip-min:'.$request->ip())
                ->response($backWith('Slow down: you can run AI triage a few times per minute. Please try again shortly.')),
            Limit::perDay($limits('ai_per_day_per_ip'))
                ->by('ip-day:'.$request->ip())
                ->response($backWith('You have reached today\'s AI triage limit for this demo.')),
            Limit::perDay($limits('ai_per_day_global'))
                ->by('global-day')
                ->response($backWith('The demo has reached its daily AI budget. Rules and the review queue still work; AI triage resumes tomorrow.')),
        ]);

        RateLimiter::for('reviews', fn (Request $request) => Limit::perMinute($limits('reviews_per_minute_per_ip'))
            ->by($request->ip())
            ->response($backWith('Too many actions. Please wait a moment.')));
    }
}

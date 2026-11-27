<?php

namespace App\Providers;

use App\Auth\TenantUserProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Auth::provider('tenant', fn ($app, array $config) => new TenantUserProvider(
            $app['hash'], $config['model'], $app->make(TenantContext::class),
        ));

        // Cookie authentication only. Without this, a Bearer header would make Sanctum
        // query the token table, which does not exist.
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);

        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? mb_strtolower(trim($email), 'UTF-8') : '';
            $network = self::network((string) $request->ip());

            return [
                Limit::perMinute(5)->by('email-ip:'.hash('sha256', $email.'|'.$network)),
                Limit::perMinute(20)->by('ip:'.$network),
            ];
        });

        RateLimiter::for('csrf', fn (Request $request) => Limit::perMinute(60)->by(self::network((string) $request->ip())));

        RateLimiter::for('authenticated', function (Request $request) {
            $user = $request->user();
            $limits = [Limit::perMinute(300)->by('session:'.$user->getAuthIdentifier().'|'.$request->session()->getId())];

            // Ceiling so that many sessions do not multiply the limit. The demo account is shared.
            if (! $user->is_demo) {
                $limits[] = Limit::perMinute(1200)->by('user:'.$user->getAuthIdentifier());
            }

            return $limits;
        });
    }

    /** IPv6 clients control a whole /64: count it as one. */
    public static function network(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        $packed = (string) inet_pton($ip);

        // IPv4 written as IPv6 (::ffff:a.b.c.d) must not share one /64 bucket.
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}

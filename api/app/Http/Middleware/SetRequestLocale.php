<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/** pt-BR (default) or en: the user's locale when known, otherwise Accept-Language. */
class SetRequestLocale
{
    public static function apply(?string $locale): void
    {
        App::setLocale($locale === 'en' ? 'en' : 'pt_BR');
    }

    public static function fromHeader(Request $request): string
    {
        return str_starts_with((string) $request->getPreferredLanguage(['pt_BR', 'en']), 'en') ? 'en' : 'pt-BR';
    }

    public function handle(Request $request, Closure $next): Response
    {
        self::apply(self::fromHeader($request));

        $response = $next($request);
        $response->headers->set('Content-Language', str_replace('_', '-', App::getLocale()));

        return $response;
    }
}

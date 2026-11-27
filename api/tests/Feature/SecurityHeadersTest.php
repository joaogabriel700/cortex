<?php

it('sends the security headers on every API response, errors included', function (string $method, string $uri, int $status) {
    $response = $this->json($method, $uri, [], ['Origin' => 'https://example.com']);

    $response->assertStatus($status)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    'health' => ['GET', '/api/health', 200],
    'unknown route' => ['GET', '/api/nope', 404],
    'wrong method' => ['DELETE', '/api/health', 405],
    'csrf cookie' => ['GET', '/sanctum/csrf-cookie', 204],
]);

it('keeps the session cookie host-only', function () {
    expect(config('session.domain'))->toBeNull()
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');
});

it('allows no cross-origin preflight', function () {
    $this->call('OPTIONS', '/api/health', server: [
        'HTTP_ORIGIN' => 'https://example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])->assertHeaderMissing('Access-Control-Allow-Origin');
});

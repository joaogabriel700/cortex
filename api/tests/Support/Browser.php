<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Facade;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One device of the web app: keeps its cookies and sends them like a browser would.
 * Between requests it drops what a real PHP process would not keep (guards, session store).
 */
class Browser
{
    /** @var array<string, string> raw (encrypted) cookie values */
    public array $cookies = [];

    public function __construct(
        private readonly TestCase $test,
        private readonly ?string $origin = 'http://localhost:8080',
        private readonly string $ip = '127.0.0.1',
    ) {}

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->send('GET', $uri, [], $headers);
    }

    /** A write with the CSRF token of the cookie, as the web app does. */
    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        $token = $this->cookies['XSRF-TOKEN'] ?? null;

        return $this->send('POST', $uri, $data, $headers + ($token === null ? [] : ['X-XSRF-TOKEN' => $token]));
    }

    public function send(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $this->forgetRequestState();

        $headers += ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'Accept-Language' => 'pt-BR,pt;q=0.9'];

        if ($this->origin !== null) {
            $headers += ['Origin' => $this->origin];
        }

        $server = ['REMOTE_ADDR' => $this->ip];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : "HTTP_{$key}"] = $value;
        }

        $response = $this->test->call($method, $uri, [], $this->cookies, [], $server,
            $method === 'GET' ? null : json_encode((object) $data));

        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        $this->forgetRequestState();

        return $response;
    }

    public function signIn(string $email, string $password): TestResponse
    {
        $this->get('/sanctum/csrf-cookie')->assertNoContent();

        return $this->post('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function forgetRequestState(): void
    {
        app('auth')->forgetGuards();
        app()->forgetInstance('auth.driver');
        app()->forgetInstance('session.store');
        Facade::clearResolvedInstance('session.store');
        app('session')->forgetDrivers();
        app('cookie')->flushQueuedCookies();
        app()->forgetScopedInstances();
        config(['auth.defaults.guard' => 'web']);
    }
}

<?php

use App\Support\Database\RoleGuard;

it('reports a healthy API and database', function () {
    $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok', 'database' => 'ok']);
    $this->get('/up')->assertOk();
});

it('allows no cross-origin access', function () {
    $this->getJson('/api/health', ['Origin' => 'https://example.com'])
        ->assertOk()
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('reports 503 without details when the database role is unsafe', function () {
    $this->mock(RoleGuard::class)->shouldReceive('assertSafe')->andThrow(new RuntimeException('secret detail'));

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertExactJson(['status' => 'error', 'database' => 'unavailable']);
});

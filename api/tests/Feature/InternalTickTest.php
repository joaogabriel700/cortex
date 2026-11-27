<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Cache::flush();
    Process::fake();
});

it('does not exist while no secret is configured', function (?string $secret) {
    config(['services.tick.secret' => $secret]);

    $this->postJson('/api/internal/tick', [], ['Authorization' => 'Bearer '.$secret])->assertNotFound();

    Process::assertNothingRan();
})->with(['unset' => null, 'empty' => '', 'too short' => 'short-secret']);

it('rejects a missing or wrong secret', function (array $headers) {
    config(['services.tick.secret' => str_repeat('a', 48)]);

    $this->postJson('/api/internal/tick', [], $headers)->assertUnauthorized();

    Process::assertNothingRan();
})->with([
    'missing' => [[]],
    'wrong' => [['Authorization' => 'Bearer '.str_repeat('b', 48)]],
]);

it('runs the tick command when the secret matches', function () {
    config(['services.tick.secret' => str_repeat('a', 48)]);

    $this->postJson('/api/internal/tick', [], ['Authorization' => 'Bearer '.str_repeat('a', 48)])
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);

    Process::assertRan(fn ($process) => $process->command === ['tick']);
});

it('reports a failed tick without leaking its output', function () {
    config(['services.tick.secret' => str_repeat('a', 48)]);
    Process::fake(['*' => Process::result(output: 'internal detail', exitCode: 1)]);

    $this->postJson('/api/internal/tick', [], ['Authorization' => 'Bearer '.str_repeat('a', 48)])
        ->assertStatus(500)
        ->assertExactJson(['status' => 'error']);
});

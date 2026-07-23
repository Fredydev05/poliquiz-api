<?php

declare(strict_types=1);

use App\Models\User;

it('registers a host and returns a token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Profe Matías',
        'email' => 'matias@fpune.edu.py',
        'password' => 'secret-123',
    ]);

    $response->assertCreated()->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);
    expect(User::where('email', 'matias@fpune.edu.py')->exists())->toBeTrue();
});

it('rejects duplicate emails on register', function () {
    User::factory()->create(['email' => 'dup@fpune.edu.py']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Otro',
        'email' => 'dup@fpune.edu.py',
        'password' => 'secret-123',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('logs in with valid credentials', function () {
    User::factory()->create(['email' => 'host@fpune.edu.py', 'password' => 'secret-123']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'host@fpune.edu.py',
        'password' => 'secret-123',
    ])->assertOk()->assertJsonStructure(['user', 'token']);
});

it('rejects invalid credentials', function () {
    User::factory()->create(['email' => 'host@fpune.edu.py', 'password' => 'secret-123']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'host@fpune.edu.py',
        'password' => 'wrong-password',
    ])->assertUnprocessable();
});

it('returns the authenticated user and logs out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
});

it('blocks guest access to protected routes', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->getJson('/api/v1/quizzes')->assertUnauthorized();
});

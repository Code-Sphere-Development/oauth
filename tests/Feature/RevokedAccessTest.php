<?php

use CodeSphere\OAuth\Http\Middleware\RefreshProfileMiddleware;
use CodeSphere\OAuth\Services\CodeSphereService;
use Illuminate\Foundation\Auth\User;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // See LocaleTest: the provider's web group middleware does not reach
    // routes defined in a test.
    Route::middleware([StartSession::class, RefreshProfileMiddleware::class])
        ->get('/_protected', fn () => 'ok');
});

function staleSession(): array
{
    return [
        CodeSphereService::SESSION_USER_KEY => ['id' => 1, 'language' => 'de'],
        CodeSphereService::SESSION_FETCHED_AT_KEY => now()->subHour()->timestamp,
    ];
}

function signedInUser(array $tokens = []): User
{
    return (new User)->forceFill([
        'id' => 1,
        'access_token' => 'access',
        'token_expires_at' => now()->addHour(),
        ...$tokens,
    ]);
}

test('a rejected access token signs the user out', function () {
    Http::fake(['*/api/user/profile' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->actingAs(signedInUser())
        ->withSession(staleSession())
        ->get('/_protected')
        ->assertRedirect(route('login'))
        ->assertSessionMissing(CodeSphereService::SESSION_USER_KEY);

    $this->assertGuest();
});

test('a revoked refresh token signs the user out', function () {
    Http::fake(['*/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->actingAs(signedInUser(['token_expires_at' => now()->subMinute(), 'refresh_token' => 'refresh']))
        ->withSession(staleSession())
        ->get('/_protected')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('json requests get a 401 instead of a redirect', function () {
    Http::fake(['*/api/user/profile' => Http::response([], 403)]);

    $this->actingAs(signedInUser())
        ->withSession(staleSession())
        ->getJson('/_protected')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Your session has ended. Please sign in again.']);
});

test('a rejection noticed elsewhere signs the user out on the next request', function () {
    Http::fake();

    $this->actingAs(signedInUser())
        ->withSession([
            CodeSphereService::SESSION_USER_KEY => ['id' => 1],
            CodeSphereService::SESSION_FETCHED_AT_KEY => now()->timestamp,
            CodeSphereService::SESSION_REVOKED_KEY => true,
        ])
        ->get('/_protected')
        ->assertRedirect(route('login'));

    $this->assertGuest();
    Http::assertNothingSent();
});

test('server errors and outages keep the user signed in', function ($response) {
    Http::fake(['*/api/user/profile' => $response]);

    $this->actingAs(signedInUser())
        ->withSession(staleSession())
        ->get('/_protected')
        ->assertOk();

    $this->assertAuthenticated();
})->with([
    'server error' => fn () => Http::response([], 500),
    'connection failure' => fn () => Http::failedConnection(),
]);

test('users without CodeSphere tokens are left alone', function () {
    Http::fake();

    $this->actingAs((new User)->forceFill(['id' => 1]))
        ->withSession(staleSession())
        ->get('/_protected')
        ->assertOk();

    Http::assertNothingSent();
});

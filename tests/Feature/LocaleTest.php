<?php

use CodeSphere\OAuth\Http\Middleware\LangMiddleware;
use CodeSphere\OAuth\Http\Middleware\RefreshProfileMiddleware;
use CodeSphere\OAuth\Services\CodeSphereService;
use Illuminate\Foundation\Auth\User;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['app.locale' => 'en']);

    // Under Testbench the middleware the provider pushes onto the web group
    // does not reach routes defined in a test, so list them explicitly.
    Route::middleware([StartSession::class, RefreshProfileMiddleware::class, LangMiddleware::class])
        ->get('/_locale', fn () => app()->getLocale().'|'.Carbon::getLocale());
});

function codeSphereUser(): User
{
    return (new User)->forceFill([
        'id' => 1,
        'access_token' => 'access',
        'token_expires_at' => now()->addHour(),
    ]);
}

function cachedProfile(string $language, int $fetchedAt): array
{
    return [
        CodeSphereService::SESSION_USER_KEY => ['id' => 1, 'language' => $language],
        CodeSphereService::SESSION_FETCHED_AT_KEY => $fetchedAt,
    ];
}

test('guests get the best supported Accept-Language', function (string $header, string $expected) {
    $this->get('/_locale', ['Accept-Language' => $header])->assertSeeText($expected.'|'.$expected);
})->with([
    'german' => ['de-CH,de;q=0.9,en;q=0.8', 'de'],
    'english' => ['en-US,en;q=0.9', 'en'],
    'unsupported first' => ['fr-FR,fr;q=0.9,de;q=0.5', 'de'],
    'unsupported only' => ['fr-FR', 'en'],
]);

test('the AccountSphere profile language beats the browser', function () {
    Http::fake();

    $this->actingAs(codeSphereUser())
        ->withSession(cachedProfile('de', now()->timestamp))
        ->get('/_locale', ['Accept-Language' => 'en-US'])
        ->assertSeeText('de|de');

    Http::assertNothingSent();
});

test('an explicit switch in the app beats the profile', function () {
    $this->actingAs(codeSphereUser())
        ->withSession(['locale' => 'en', ...cachedProfile('de', now()->timestamp)])
        ->get('/_locale')
        ->assertSeeText('en|en');
});

test('unsupported profile languages are ignored', function () {
    $this->actingAs(codeSphereUser())
        ->withSession(cachedProfile('xx', now()->timestamp))
        ->get('/_locale', ['Accept-Language' => 'de'])
        ->assertSeeText('de|de');
});

test('a stale profile is refreshed so a language change arrives without a new login', function () {
    Http::fake(['*/api/user/profile' => Http::response(['user' => ['id' => 1, 'language' => 'en'], 'groups' => []])]);

    $this->actingAs(codeSphereUser())
        ->withSession(cachedProfile('de', now()->subHour()->timestamp))
        ->get('/_locale')
        ->assertSeeText('en|en')
        ->assertSessionHas(CodeSphereService::SESSION_USER_KEY.'.language', 'en');
});

test('a failed refresh keeps the cached profile and is not retried on every request', function () {
    Http::fake(['*/api/user/profile' => Http::failedConnection()]);

    $this->actingAs(codeSphereUser())
        ->withSession(cachedProfile('de', now()->subHour()->timestamp))
        ->get('/_locale')
        ->assertOk()
        ->assertSeeText('de|de');

    $this->get('/_locale')->assertSeeText('de|de');

    Http::assertSentCount(1);
});

test('the refresh can be disabled', function () {
    config(['codesphere.refresh_profile' => false]);
    Http::fake();

    $this->actingAs(codeSphereUser())
        ->withSession(cachedProfile('de', now()->subHour()->timestamp))
        ->get('/_locale')
        ->assertSeeText('de|de');

    Http::assertNothingSent();
});

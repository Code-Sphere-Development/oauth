<?php

use CodeSphere\OAuth\Http\Middleware\LangMiddleware;
use CodeSphere\OAuth\Http\Middleware\RefreshProfileMiddleware;
use CodeSphere\OAuth\Services\CodeSphereService;
use CodeSphere\OAuth\Support\Locales;
use CodeSphere\OAuth\Tests\Fakes\FakeLaravelLocalization;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['codesphere.locales' => ['de', 'en']]);
    Locales::flush();

    // See LocaleTest: the provider's web group middleware does not reach
    // routes defined in a test.
    Route::middleware([StartSession::class, RefreshProfileMiddleware::class, LangMiddleware::class])
        ->get('/_settings', fn () => app()->getLocale());
});

function switcherUser(array $tokens = []): User
{
    return (new User)->forceFill([
        'id' => 1,
        'access_token' => 'access',
        'token_expires_at' => now()->addHour(),
        ...$tokens,
    ]);
}

function englishProfile(): array
{
    return [
        CodeSphereService::SESSION_USER_KEY => ['id' => 1, 'name' => 'Ada', 'language' => 'en'],
        CodeSphereService::SESSION_GROUPS_KEY => [['id' => 7]],
        CodeSphereService::SESSION_FETCHED_AT_KEY => now()->subMinutes(5)->timestamp,
    ];
}

test('the language is saved in CodeSphere Accounts and applies on the next request', function () {
    Http::fake(['*/api/user/settings' => Http::response(['user' => ['id' => 1, 'name' => 'Ada', 'language' => 'de']])]);
    $this->freezeTime();

    $this->actingAs(switcherUser())
        ->withSession(englishProfile())
        ->from('/_settings')
        ->post(route('codesphere.language'), ['locale' => 'de'])
        ->assertRedirect('/_settings')
        ->assertSessionHasNoErrors()
        ->assertSessionHas(CodeSphereService::SESSION_USER_KEY, ['id' => 1, 'name' => 'Ada', 'language' => 'de'])
        ->assertSessionHas(CodeSphereService::SESSION_GROUPS_KEY, [['id' => 7]])
        ->assertSessionHas(CodeSphereService::SESSION_FETCHED_AT_KEY, now()->timestamp);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'https://account.code-sphere.test/api/user/settings'
        && $request->hasHeader('Authorization', 'Bearer access')
        && $request->hasHeader('Accept', 'application/json')
        && $request->data() === ['language' => 'de']);

    $this->get('/_settings')->assertSeeText('de');
    Http::assertSentCount(1);
});

test('JSON requests get the new locale', function () {
    Http::fake(['*/api/user/settings' => Http::response(['user' => ['id' => 1, 'language' => 'de']])]);

    $this->actingAs(switcherUser())
        ->withSession(englishProfile())
        ->postJson(route('codesphere.language'), ['locale' => 'de'])
        ->assertOk()
        ->assertExactJson(['locale' => 'de']);
});

test('with URL prefixes the user returns to the page in the new language', function () {
    app()->instance('laravellocalization', new FakeLaravelLocalization(['de', 'en']));
    Http::fake(['*/api/user/settings' => Http::response(['user' => ['id' => 1, 'language' => 'de']])]);

    $this->actingAs(switcherUser())
        ->withSession(englishProfile())
        ->from('http://localhost/en/invoices?page=2')
        ->post(route('codesphere.language'), ['locale' => 'de'])
        ->assertRedirect('http://localhost/de/invoices?page=2');
});

test('only available locales are accepted', function (array $input) {
    Http::fake();

    $this->actingAs(switcherUser())
        ->withSession(englishProfile())
        ->from('/_settings')
        ->post(route('codesphere.language'), $input)
        ->assertRedirect('/_settings')
        ->assertSessionHasErrors('locale');

    $this->postJson(route('codesphere.language'), $input)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('locale');

    Http::assertNothingSent();
})->with([
    'unavailable' => [['locale' => 'fr']],
    'missing' => [[]],
    'not a string' => [['locale' => ['de']]],
]);

test('a token without the settings scope asks the user to sign in again', function () {
    Http::fake(['*/api/user/settings' => Http::response(['message' => 'Invalid scope(s) provided.'], 403)]);

    $this->actingAs(switcherUser())
        ->withSession(englishProfile())
        ->from('/_settings')
        ->post(route('codesphere.language'), ['locale' => 'de'])
        ->assertRedirect('/_settings')
        ->assertSessionHasErrors(['locale' => 'Please sign in again to change your language.'])
        ->assertSessionHas(CodeSphereService::SESSION_USER_KEY.'.language', 'en')
        ->assertSessionMissing(CodeSphereService::SESSION_REVOKED_KEY);

    $this->postJson(route('codesphere.language'), ['locale' => 'de'])
        ->assertForbidden()
        ->assertExactJson(['message' => 'Please sign in again to change your language.']);

    $this->assertAuthenticated();
});

test('the messages are translated', function () {
    app()->setLocale('de');

    expect(__('Please sign in again to change your language.'))
        ->toBe('Bitte melde dich erneut an, um deine Sprache zu ändern.')
        ->and(__('The language could not be saved. Please try again later.'))
        ->toBe('Die Sprache konnte nicht gespeichert werden. Bitte versuche es später erneut.');
});

test('server errors and outages keep the language and the session', function ($response) {
    Http::fake(['*/api/user/settings' => $response]);

    $this->actingAs(switcherUser())
        ->withSession(englishProfile())
        ->from('/_settings')
        ->post(route('codesphere.language'), ['locale' => 'de'])
        ->assertRedirect('/_settings')
        ->assertSessionHasErrors(['locale' => 'The language could not be saved. Please try again later.'])
        ->assertSessionHas(CodeSphereService::SESSION_USER_KEY.'.language', 'en')
        ->assertSessionMissing(CodeSphereService::SESSION_REVOKED_KEY);

    $this->postJson(route('codesphere.language'), ['locale' => 'de'])
        ->assertStatus(503)
        ->assertExactJson(['message' => 'The language could not be saved. Please try again later.']);

    $this->assertAuthenticated();
})->with([
    'server error' => fn () => Http::response([], 500),
    'connection failure' => fn () => Http::failedConnection(),
]);

test('a rejected token signs the user out like a failed refresh', function (int $status) {
    Http::fake(['*/api/user/settings' => Http::response([], $status)]);

    $this->actingAs(switcherUser())
        ->withSession(englishProfile())
        ->from('/_settings')
        ->post(route('codesphere.language'), ['locale' => 'de'])
        ->assertRedirect('/_settings')
        ->assertSessionHas(CodeSphereService::SESSION_REVOKED_KEY, true);

    $this->get('/_settings')->assertRedirect(route('login'));
    $this->assertGuest();
})->with([400, 401]);

test('JSON requests with a rejected token get a 401', function () {
    Http::fake(['*/api/user/settings' => Http::response([], 401)]);

    $this->actingAs(switcherUser())
        ->withSession(englishProfile())
        ->postJson(route('codesphere.language'), ['locale' => 'de'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Your session has ended. Please sign in again.']);
});

test('a revoked refresh token signs the user out', function () {
    Http::fake(['*/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->actingAs(switcherUser(['token_expires_at' => now()->subMinute(), 'refresh_token' => 'refresh']))
        ->withSession(englishProfile())
        ->postJson(route('codesphere.language'), ['locale' => 'de'])
        ->assertUnauthorized();

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/api/user/settings'));
});

test('users without CodeSphere tokens get an error', function () {
    Http::fake();

    $this->actingAs((new User)->forceFill(['id' => 1]))
        ->withSession(englishProfile())
        ->postJson(route('codesphere.language'), ['locale' => 'de'])
        ->assertStatus(503);

    Http::assertNothingSent();
});

test('guests are sent to the login', function () {
    Http::fake();

    $this->post(route('codesphere.language'), ['locale' => 'de'])
        ->assertRedirect(route('login'));

    $this->postJson(route('codesphere.language'), ['locale' => 'de'])->assertUnauthorized();

    Http::assertNothingSent();
});

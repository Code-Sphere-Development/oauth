<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

function withOAuthError(string $message): array
{
    return ['errors' => (new ViewErrorBag)->put('default', new MessageBag(['oauth' => $message]))];
}

test('login starts the oauth flow when there is no error', function () {
    $this->get('/login')->assertRedirect(route('codesphere.redirect'));
});

test('login shows the error instead of looping back to accounts', function () {
    $switchUrl = 'https://account.code-sphere.test/oauth/logout?'.http_build_query([
        'client_id' => 'client-123',
        'post_logout_redirect_uri' => route('login'),
    ]);

    $this->withSession(withOAuthError('Your account type is not allowed for this application.'))
        ->get('/login')
        ->assertOk()
        ->assertSee('TestSphere')
        ->assertSee('Your account type is not allowed for this application.')
        ->assertSee(e($switchUrl), false)
        ->assertSee(route('codesphere.redirect'));
});

test('failed callback ends on the error page', function () {
    $this->withSession(['codesphere_oauth_state' => 'expected'])
        ->get('/auth/callback?state=forged&code=abc')
        ->assertRedirect(route('login'));

    $this->get('/login')
        ->assertOk()
        ->assertSee('Invalid OAuth state.');
});

test('error page is translated to german', function () {
    app()->setLocale('de');

    $this->withSession(['locale' => 'de', ...withOAuthError(__('Your account type is not allowed for this application.'))])
        ->get('/login')
        ->assertOk()
        ->assertSee('Anmeldung fehlgeschlagen')
        ->assertSee('Mit diesem Kontotyp kannst du diese Anwendung nicht nutzen.')
        ->assertSee('Mit anderem Konto anmelden')
        ->assertSee('Erneut versuchen');
});

test('switch account link is hidden when single sign-out is disabled', function () {
    config(['codesphere.routes.sso_logout' => false]);

    $this->withSession(withOAuthError('Authentication failed.'))
        ->get('/login')
        ->assertOk()
        ->assertSee('Authentication failed.')
        ->assertDontSee('/oauth/logout');
});

test('logout still ends the central session', function () {
    $this->actingAs(new User)
        ->post('/auth/logout')
        ->assertRedirect('https://account.code-sphere.test/oauth/logout?'.http_build_query([
            'client_id' => 'client-123',
            'post_logout_redirect_uri' => url('/'),
        ]));
});

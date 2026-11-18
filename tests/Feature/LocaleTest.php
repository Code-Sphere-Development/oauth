<?php

use CodeSphere\OAuth\Http\Middleware\LangMiddleware;
use CodeSphere\OAuth\Http\Middleware\RefreshProfileMiddleware;
use CodeSphere\OAuth\Services\CodeSphereService;
use CodeSphere\OAuth\Support\Locales;
use CodeSphere\OAuth\Tests\Fakes\FakeLaravelLocalization;
use Illuminate\Foundation\Auth\User;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['app.locale' => 'en', 'codesphere.locales' => ['de', 'en']]);
    Locales::flush();

    // Under Testbench the middleware the provider pushes onto the web group
    // does not reach routes defined in a test, so list them explicitly.
    Route::middleware([StartSession::class, RefreshProfileMiddleware::class, LangMiddleware::class])
        ->get('/_locale', fn () => app()->getLocale().'|'.Carbon::getLocale());
});

afterEach(function () {
    Locales::flush();
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

test('a locale in the session no longer overrides the profile', function () {
    $this->actingAs(codeSphereUser())
        ->withSession(['locale' => 'en', ...cachedProfile('de', now()->timestamp)])
        ->get('/_locale')
        ->assertSeeText('de|de');
});

test('a locale in the session is ignored for guests', function () {
    $this->withSession(['locale' => 'de'])
        ->get('/_locale', ['Accept-Language' => 'en-US'])
        ->assertSeeText('en|en');
});

test('the fallback is english, not app.locale', function () {
    config(['app.locale' => 'de']);

    $this->get('/_locale', ['Accept-Language' => 'fr-FR'])->assertSeeText('en|en');
    $this->get('/_locale')->assertSeeText('en|en');
});

test('a profile language the app has no translations for falls back to english', function () {
    config(['app.locale' => 'de']);

    $this->actingAs(codeSphereUser())
        ->withSession(cachedProfile('fr', now()->timestamp))
        ->get('/_locale', ['Accept-Language' => 'fr-FR'])
        ->assertSeeText('en|en');
});

test('an unavailable profile language beats the browser and falls back to english', function () {
    $this->actingAs(codeSphereUser())
        ->withSession(cachedProfile('xx', now()->timestamp))
        ->get('/_locale', ['Accept-Language' => 'de'])
        ->assertSeeText('en|en');
});

test('signed-in users without a profile language get the browser language', function () {
    $this->actingAs(codeSphereUser())
        ->withSession([
            CodeSphereService::SESSION_USER_KEY => ['id' => 1, 'language' => null],
            CodeSphereService::SESSION_FETCHED_AT_KEY => now()->timestamp,
        ])
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

test('a failed refresh keeps the cached profile and is retried after a short backoff', function () {
    Http::fake(['*/api/user/profile' => Http::failedConnection()]);

    $this->actingAs(codeSphereUser())
        ->withSession(cachedProfile('de', now()->subHour()->timestamp))
        ->get('/_locale')
        ->assertOk()
        ->assertSeeText('de|de');

    $this->get('/_locale')->assertSeeText('de|de');
    Http::assertSentCount(1);

    // Other sessions skip the refresh while AccountSphere is unreachable.
    $this->flushSession()
        ->withSession(cachedProfile('de', now()->subHour()->timestamp))
        ->get('/_locale')
        ->assertSeeText('de|de');
    Http::assertSentCount(1);

    $this->travel(CodeSphereService::FAILED_REFRESH_RETRY + 1)->seconds();
    $this->get('/_locale')->assertOk();
    Http::assertSentCount(2);
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

describe('available locales', function () {
    beforeEach(function () {
        $this->langPath = sys_get_temp_dir().'/codesphere-lang-'.uniqid();
        File::ensureDirectoryExists($this->langPath.'/fr');
        File::ensureDirectoryExists($this->langPath.'/vendor/package/xx');
        File::ensureDirectoryExists($this->langPath.'/pt_BR');
        File::put($this->langPath.'/de.json', '{}');
        File::put($this->langPath.'/README.md', '');

        app()->useLangPath($this->langPath);
        config(['codesphere.locales' => null]);
    });

    afterEach(function () {
        File::deleteDirectory($this->langPath);
    });

    test('are detected from the lang directory', function () {
        expect(app(Locales::class)->available())->toBe(['de', 'en', 'fr']);

        $this->get('/_locale', ['Accept-Language' => 'fr-FR'])->assertSeeText('fr|fr');
        $this->get('/_locale', ['Accept-Language' => 'it-IT'])->assertSeeText('en|en');
    });

    test('always include english', function () {
        File::cleanDirectory($this->langPath);

        expect(app(Locales::class)->available())->toBe(['en']);
    });

    test('are detected once per process', function () {
        expect(app(Locales::class)->available())->toBe(['de', 'en', 'fr']);

        File::put($this->langPath.'/es.json', '{}');
        expect(app(Locales::class)->available())->toBe(['de', 'en', 'fr']);

        Locales::flush();
        expect(app(Locales::class)->available())->toBe(['de', 'en', 'es', 'fr']);
    });

    test('can be set in the config', function () {
        config(['codesphere.locales' => ['de']]);

        expect(app(Locales::class)->available())->toBe(['de']);
        $this->get('/_locale', ['Accept-Language' => 'fr-FR,de;q=0.5'])->assertSeeText('de|de');
    });

    test('an empty list in the config falls back to detection', function () {
        config(['codesphere.locales' => []]);

        expect(app(Locales::class)->available())->toBe(['de', 'en', 'fr']);
    });
});

describe('URL locale prefixes (mcamara/laravel-localization)', function () {
    beforeEach(function () {
        $this->localization = new FakeLaravelLocalization(['de', 'en']);
        app()->instance('laravellocalization', $this->localization);

        foreach (['de', 'en'] as $prefix) {
            Route::middleware([StartSession::class, RefreshProfileMiddleware::class, LangMiddleware::class])
                ->prefix($prefix)
                ->group(function () {
                    Route::get('/_page', fn () => app()->getLocale());
                    Route::post('/_page', fn () => app()->getLocale());
                });
        }
    });

    test('signed-in users are redirected to the URL of their language', function () {
        $this->actingAs(codeSphereUser())
            ->withSession(cachedProfile('de', now()->timestamp))
            ->get('/en/_page?tab=2')
            ->assertRedirect('http://localhost/de/_page?tab=2');
    });

    test('signed-in users on the URL of their language are not redirected', function () {
        $this->actingAs(codeSphereUser())
            ->withSession(cachedProfile('de', now()->timestamp))
            ->get('/de/_page')
            ->assertOk()
            ->assertSeeText('de');

        expect($this->localization->currentLocale)->toBe('de');
    });

    test('guests keep the locale of the URL', function () {
        $this->get('/en/_page', ['Accept-Language' => 'de-DE'])
            ->assertOk()
            ->assertSeeText('en');

        expect($this->localization->currentLocale)->toBe('en');
    });

    test('form submissions and JSON requests are not redirected', function () {
        $this->actingAs(codeSphereUser())
            ->withSession(cachedProfile('de', now()->timestamp))
            ->post('/en/_page')
            ->assertOk()
            ->assertSeeText('de');

        $this->getJson('/en/_page')->assertOk()->assertSeeText('de');

        expect($this->localization->currentLocale)->toBe('de');
    });

    test('URLs without a locale prefix are not redirected', function () {
        $this->actingAs(codeSphereUser())
            ->withSession(cachedProfile('de', now()->timestamp))
            ->get('/_locale')
            ->assertOk()
            ->assertSeeText('de|de');
    });

    test('signed-in users without a profile language keep the URL locale', function () {
        $this->actingAs(codeSphereUser())
            ->withSession([
                CodeSphereService::SESSION_USER_KEY => ['id' => 1],
                CodeSphereService::SESSION_FETCHED_AT_KEY => now()->timestamp,
            ])
            ->get('/de/_page', ['Accept-Language' => 'en-US'])
            ->assertOk()
            ->assertSeeText('de');
    });

    test('an unavailable profile language redirects to the english URL', function () {
        $this->actingAs(codeSphereUser())
            ->withSession(cachedProfile('fr', now()->timestamp))
            ->get('/de/_page')
            ->assertRedirect('http://localhost/en/_page');
    });
});

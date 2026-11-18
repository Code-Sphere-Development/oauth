# code-sphere/oauth

Stateless OAuth2 client for CodeSphere Accounts. Drop-in single sign-on for
all CodeSphere apps.

## What it does

- Implements the OAuth2 authorization code flow against
  `CodeSphere Accounts`.
- Stores **only** the minimum locally: `codesphere_id`, `access_token`,
  `refresh_token`, `token_expires_at`. **No name, email, or avatar in the
  local database.**
- Caches the user profile and group memberships in the **session** so views
  can use `$user->name`, `$user->email`, etc. without hitting the API on
  every request.
- Provides role checks against CodeSphere groups
  (`isMemberOfGroup`, `isOwnerOfGroup`, `canManageGroup`).
- Refreshes access tokens automatically when they expire.

## What it does NOT do

- It does not manage app-specific data (companies, teams, projects). Apps
  link their own data to CodeSphere groups via a `codesphere_group_id`
  column on their domain models, not via this package.
- It does not sync users into your local DB beyond a stub row keyed by
  `codesphere_id`. There is no `firstname`, `lastname`, `email`, or
  `avatar` column.

## Install (local path repository)

In your app's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/codesphere/oauth"
        }
    ],
    "require": {
        "code-sphere/oauth": "*"
    }
}
```

Then:

```bash
composer update code-sphere/oauth
php artisan vendor:publish --tag=codesphere-config
php artisan vendor:publish --tag=codesphere-migrations
php artisan migrate
```

## Configuration

In `.env`:

```
CODESPHERE_ACCOUNTS_URL=https://account.code-sphere.de
CODESPHERE_CLIENT_ID=...
CODESPHERE_CLIENT_SECRET=...
CODESPHERE_REDIRECT_URI=https://your-app.example/auth/callback
CODESPHERE_APP_KEY=invoicesphere
CODESPHERE_ALLOWED_ACCOUNT_TYPES=business
CODESPHERE_HOME_ROUTE=dashboard
```

## Use the trait on your User model

```php
use CodeSphere\OAuth\Concerns\HasCodeSphereIdentity;

class User extends Authenticatable
{
    use HasCodeSphereIdentity;

    protected $fillable = [
        'codesphere_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        // ...your app-specific fields like current_company_id
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }
}
```

## Listen for the login event

The package fires `CodeSphere\OAuth\Events\CodeSphereLoggedIn` after a
successful login. Listen for it to sync your app-specific records:

```php
use CodeSphere\OAuth\Events\CodeSphereLoggedIn;

class SyncCompaniesAfterLogin
{
    public function handle(CodeSphereLoggedIn $event): void
    {
        foreach ($event->groups() as $group) {
            Company::firstOrCreate(
                ['codesphere_group_id' => $group['id']],
                ['name' => $group['name']],
            );
        }
    }
}
```

## Routes provided

| Method | URI                | Name                 | Purpose                          |
|--------|--------------------|----------------------|----------------------------------|
| GET    | /auth/redirect     | codesphere.redirect  | Start the OAuth flow             |
| GET    | /auth/callback     | codesphere.callback  | OAuth callback handler           |
| POST   | /auth/logout       | codesphere.logout    | Log out locally                  |
| POST   | /auth/language     | codesphere.language  | Save the language in CodeSphere Accounts ([switcher](#language-switcher)) |
| GET    | /login (optional)  | login                | Starts the OAuth flow, or shows the last sign-in error |

The `login` route is registered automatically. Set
`CODESPHERE_REGISTER_LOGIN_ROUTE=false` in `.env` if your app provides its
own `login` route.

### Sign-in errors

When the callback fails (invalid state, disallowed account type, …) the
package redirects to `/login` with an `oauth` error. Instead of starting the
flow again — CodeSphere Accounts would send an already signed-in user straight
back, an endless redirect loop — `/login` then shows the message with
"Try again" and "Sign in with a different account" (via
CodeSphere Accounts `/oauth/logout`, only when `sso_logout` is enabled).

The page is self-contained. To use your app's layout, publish and edit it:

```bash
php artisan vendor:publish --tag=codesphere-views
```

German translations ship with the package (`lang/de.json`); keys in your app's
own `lang/de.json` take precedence.

## Language

The package adds two middleware to the `web` group:

- `RefreshProfileMiddleware` re-fetches the session-cached profile once it is
  older than `cache_ttl` (default 15 minutes). A failed refresh keeps the cached
  profile and is retried after 60 seconds. Disable with
  `CODESPHERE_REFRESH_PROFILE=false`.
  When CodeSphere Accounts rejects the user's tokens (HTTP 400/401/403, e.g.
  revoked access, expired refresh token, deactivated account), the user is
  signed out: browsers are redirected to `/login` (which signs them in again
  silently if their central session is still valid), JSON requests get a 401.
  Server errors and outages keep the user signed in.
- `LangMiddleware` decides the app and Carbon locale. The package alone
  decides it — apps do not need (and should remove) their own locale
  middleware. In this order:
  1. signed-in users: their language in CodeSphere Accounts (cached profile),
     or `en` if the app has no translations for it
  2. the locale prefix of the URL (`/de/…`, only with
     [mcamara/laravel-localization](#url-locale-prefixes-mcamaralaravel-localization))
  3. the `Accept-Language` header (first entry whose two-letter code is available)
  4. `en`

  `session('locale')` and `app.locale` are not used.

A language change in CodeSphere Accounts therefore reaches every app within
`cache_ttl`, also for users signed in again via the remember-me cookie.

### Available locales

Translations stay in your app; the package only picks which of them is used.
Only *available* locales are chosen — a language your app has no translations
for falls back to `en`.

By default (`'locales' => null`) they are detected from your app's `lang`
directory: every `lang/{locale}.json` file and `lang/{locale}/` directory
with a two-letter name (`de`, `fr`, …; not `lang/vendor`), plus `en`. The
result is cached per PHP process (restart Octane/queue workers after adding a
language). To pin the list, publish the config and set it:

```php
'locales' => ['de', 'en'],
```

### Language switcher

Save the user's choice in CodeSphere Accounts — it then applies to every
CodeSphere app — with the `codesphere.language` route:

```blade
<form method="POST" action="{{ route('codesphere.language') }}">
    @csrf
    <select name="locale" onchange="this.form.submit()">
        <option value="de" @selected(app()->getLocale() === 'de')>Deutsch</option>
        <option value="en" @selected(app()->getLocale() === 'en')>English</option>
    </select>
    @error('locale') <p>{{ $message }}</p> @enderror
</form>
```

The route (`POST /auth/language`, `web` + `auth`) validates `locale` against the
available locales, sends `PATCH /api/user/settings` to CodeSphere Accounts and
updates the cached profile, so the next page is in the new language. It
redirects back (with URL prefixes: to the previous page in the new language);
JSON requests get `{"locale": "de"}`. Errors are flashed as a `locale` error
(JSON: `message` with the status):

| Situation                                        | Status | Message                                                     |
|--------------------------------------------------|--------|-------------------------------------------------------------|
| Locale not available                             | 422    | validation error                                            |
| Token lacks the `settings` scope                 | 403    | Please sign in again to change your language.               |
| Token rejected (400/401), refresh token revoked  | 401    | Your session has ended. Please sign in again. (signed out on the next request) |
| CodeSphere Accounts unreachable / server error   | 503    | The language could not be saved. Please try again later.   |

The switcher needs the `settings` scope (requested by default since this
version). Tokens issued earlier do not have it: those users have to sign in
again once (e.g. log out and in) before they can change the language from an
app. If you published `config/codesphere.php`, add `'settings'` to `scopes`.

Do not store the choice in `session('locale')` or your own user table — the
package ignores both.

### URL locale prefixes (mcamara/laravel-localization)

When the app uses [mcamara/laravel-localization](https://github.com/mcamara/laravel-localization)
(the package detects its `laravellocalization` binding; there is no Composer
dependency):

- **Signed-in users**: their CodeSphere language wins. A `GET`/`HEAD` page
  request whose URL prefix differs (`/en/invoices` for a German user) is
  redirected (302) to the same page in their language (`/de/invoices`), query
  string included. Form submissions and JSON requests are not redirected; they
  just use the profile language.
- **Guests** keep the URL prefix, so shared links and search engines get the
  language in the URL.
- mcamara's current locale is set to the chosen locale as well.
- URLs without a locale prefix are never redirected. With
  `hideDefaultLocaleInURL`, a signed-in user on a prefix-less URL gets their
  language without a redirect.
- mcamara's `localeSessionRedirect` / `localeCookieRedirect` middleware are not
  needed for signed-in users; keep them only if you want them for guests.

## Development

```bash
composer install
composer ci        # Pint, PHPStan (Larastan, level 5) and Pest
```

GitHub Actions runs the same checks on every pull request, with Pest against
PHP 8.2–8.5 and Laravel 12 and 13. There is no `composer.lock`, so each
combination resolves the newest compatible dependencies.

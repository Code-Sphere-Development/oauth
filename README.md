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
  profile and is retried only after the next `cache_ttl`. Disable with
  `CODESPHERE_REFRESH_PROFILE=false`.
- `LangMiddleware` sets the app and Carbon locale from, in this order:
  1. `session('locale')` — an explicit language switch inside your app
  2. the user's language in CodeSphere Accounts (cached profile)
  3. the `Accept-Language` header
  4. `app.locale`

  Only locales listed in `codesphere.locales` (default `de`, `en`) are used.

A language change in CodeSphere Accounts therefore reaches every app within
`cache_ttl`, also for users signed in again via the remember-me cookie. Apps do
not need their own locale middleware.

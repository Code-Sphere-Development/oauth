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
| GET    | /login (optional)  | login                | Redirects to codesphere.redirect |

The `login` route is registered automatically. Set
`CODESPHERE_REGISTER_LOGIN_ROUTE=false` in `.env` if your app provides its
own `login` route.

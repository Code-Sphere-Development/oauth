<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CodeSphere Accounts URL
    |--------------------------------------------------------------------------
    |
    | The base URL of the CodeSphere Accounts OAuth2 server. All API and OAuth
    | endpoints are derived from this base URL. In production this should be
    | https://account.code-sphere.de.
    |
    */

    'url' => env('CODESPHERE_ACCOUNTS_URL', 'https://account.code-sphere.de'),

    /*
    |--------------------------------------------------------------------------
    | OAuth Client Credentials
    |--------------------------------------------------------------------------
    |
    | The client_id and client_secret obtained when registering this app as
    | an OAuth client in CodeSphere Accounts. The redirect_uri must match
    | the URI registered with the OAuth client.
    |
    */

    'client_id' => env('CODESPHERE_CLIENT_ID'),
    'client_secret' => env('CODESPHERE_CLIENT_SECRET'),
    'redirect_uri' => env('CODESPHERE_REDIRECT_URI'),

    /*
    |--------------------------------------------------------------------------
    | App Key
    |--------------------------------------------------------------------------
    |
    | A unique key identifying this app within CodeSphere Accounts. This is
    | used to filter groups by app subscription — only groups that have an
    | active subscription for this app key will be returned. Must match an
    | app_key registered in CodeSphereAccounts group_app_access.
    |
    */

    'app_key' => env('CODESPHERE_APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Account Types
    |--------------------------------------------------------------------------
    |
    | Account types this app accepts. Comma-separated list. Common values:
    | "business", "private". An empty value means all account types are
    | accepted. Used to reject login attempts from incompatible account
    | types AND to filter visible groups by type.
    |
    */

    'allowed_account_types' => array_filter(array_map(
        'trim',
        explode(',', (string) env('CODESPHERE_ALLOWED_ACCOUNT_TYPES', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) the user profile and groups should be cached in
    | the session before being re-fetched from CodeSphere Accounts. Default
    | is 15 minutes.
    |
    */

    'cache_ttl' => (int) env('CODESPHERE_CACHE_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | OAuth Scopes
    |--------------------------------------------------------------------------
    |
    | The OAuth scopes to request during the authorization flow. Adjust this
    | only if you have a reason to limit or extend the requested permissions.
    |
    */

    'scopes' => ['openid', 'profile', 'email', 'groups'],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Configuration for the routes registered by this package.
    |
    | - prefix: URL prefix for all package routes (e.g. "auth")
    | - register_login_route: when true, the package registers /login as
    |   a redirect to the OAuth flow. Set to false if your app provides
    |   its own login route.
    | - home_route: where to send the user after successful login
    | - logout_redirect: where to redirect after logout
    |
    */

    'routes' => [
        'prefix' => env('CODESPHERE_ROUTE_PREFIX', 'auth'),
        'register_login_route' => env('CODESPHERE_REGISTER_LOGIN_ROUTE', true),
        'home_route' => env('CODESPHERE_HOME_ROUTE', 'dashboard'),
        'logout_redirect' => env('CODESPHERE_LOGOUT_REDIRECT', '/'),

        /*
        | When true (default), logging out of the consumer app also
        | bounces the browser to CodeSphere Accounts /oauth/logout so
        | the central SSO session is ended too. Set to false for local
        | development against a session stub that has no /oauth/logout.
        */
        'sso_logout' => env('CODESPHERE_SSO_LOGOUT', true),
    ],

];

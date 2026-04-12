<?php

namespace CodeSphere\OAuth\Http\Controllers;

use CodeSphere\OAuth\Events\CodeSphereLoggedIn;
use CodeSphere\OAuth\Services\CodeSphereService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles the OAuth2 authorization code flow against CodeSphere Accounts.
 *
 * IMPORTANT: This controller never persists user-identifying data (name,
 * email, avatar) to the local database. It stores only:
 *   - codesphere_id (the user's id in CodeSphereAccounts)
 *   - access_token
 *   - refresh_token
 *   - token_expires_at
 *
 * Display data (name, email, avatar) lives in the session cache, populated
 * by CodeSphereService::cacheProfile() and read via the HasCodeSphereIdentity
 * trait on the local User model.
 */
class CodeSphereAuthController extends Controller
{
    public function __construct(
        protected CodeSphereService $codeSphere,
    ) {}

    /**
     * Kick off the OAuth flow: store a CSRF state and redirect the browser
     * to the CodeSphere authorization endpoint.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('codesphere_oauth_state', $state);

        return redirect()->away($this->codeSphere->getAuthorizationUrl($state));
    }

    /**
     * OAuth callback: validate state, exchange code for tokens, fetch
     * profile, upsert the local stub user, cache the profile, log in.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->input('state') !== $request->session()->pull('codesphere_oauth_state')) {
            return $this->loginError(__('Invalid OAuth state.'));
        }

        if ($request->has('error')) {
            return $this->loginError(
                $request->input('error_description', __('Authentication failed.'))
            );
        }

        try {
            $tokenData = $this->codeSphere->exchangeCodeForToken((string) $request->input('code'));
        } catch (\Throwable $e) {
            Log::error('CodeSphere token exchange failed', ['error' => $e->getMessage()]);

            return $this->loginError(__('Authentication failed. Please try again.'));
        }

        $accessToken = $tokenData['access_token'];
        $refreshToken = $tokenData['refresh_token'] ?? null;
        $expiresIn = (int) ($tokenData['expires_in'] ?? 1296000);

        try {
            $profile = $this->codeSphere->fetchUserProfile($accessToken);
        } catch (\Throwable $e) {
            Log::error('CodeSphere profile fetch failed', ['error' => $e->getMessage()]);

            return $this->loginError(__('Could not fetch user profile.'));
        }

        $userData = $profile['user'] ?? $profile;

        // Enforce account type restrictions, if configured.
        $allowedTypes = (array) config('codesphere.allowed_account_types', []);
        if (! empty($allowedTypes)
            && ! in_array($userData['account_type'] ?? null, $allowedTypes, true)) {
            return $this->loginError(
                __('Your account type is not allowed for this application.')
            );
        }

        // Upsert the minimal stub user.
        $userModel = config('auth.providers.users.model');

        $user = DB::transaction(fn () => $userModel::updateOrCreate(
            ['codesphere_id' => $userData['id']],
            [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => now()->addSeconds($expiresIn),
            ]
        ));

        // Cache the profile in the session for subsequent requests.
        $this->codeSphere->cacheProfile($profile);

        // Log the user in via the configured guard.
        Auth::login($user, true);

        // Let the host app react (e.g. sync companies, pick workspace).
        CodeSphereLoggedIn::dispatch($user, $profile);

        $homeRoute = config('codesphere.routes.home_route', 'dashboard');

        return redirect()->intended(
            \Route::has($homeRoute) ? route($homeRoute) : '/'
        );
    }

    /**
     * Log the user out locally and, when Single Sign-Out is enabled,
     * bounce the browser to CodeSphereAccounts /oauth/logout so the
     * central session is killed too. CodeSphereAccounts then redirects
     * back to the configured post-logout URL.
     *
     * When SSO logout is disabled (e.g. in local dev against a plain
     * session stub) the controller just redirects to the local
     * logout_redirect — the legacy behaviour.
     */
    public function logout(Request $request): RedirectResponse
    {
        $this->codeSphere->clearCache();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $localLandingPath = (string) config('codesphere.routes.logout_redirect', '/');
        $localLandingUrl = url($localLandingPath);

        if (config('codesphere.routes.sso_logout', true) && config('codesphere.client_id')) {
            $ssoLogoutUrl = rtrim((string) config('codesphere.url'), '/')
                .'/oauth/logout?'
                .http_build_query([
                    'client_id' => config('codesphere.client_id'),
                    'post_logout_redirect_uri' => $localLandingUrl,
                ]);

            return redirect()->away($ssoLogoutUrl);
        }

        return redirect($localLandingUrl);
    }

    protected function loginError(string $message): RedirectResponse
    {
        $target = \Route::has('login') ? route('login') : '/';

        return redirect($target)->withErrors(['oauth' => $message]);
    }
}

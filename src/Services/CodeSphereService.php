<?php

namespace CodeSphere\OAuth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * Stateless CodeSphere Accounts client.
 *
 * Handles all communication with the CodeSphere Accounts OAuth server:
 *   - OAuth authorization code flow
 *   - Token exchange and refresh
 *   - User profile and group fetching
 *   - Session-cached user identity (no DB writes for user data)
 */
class CodeSphereService
{
    public const SESSION_USER_KEY = 'codesphere.user';

    public const SESSION_GROUPS_KEY = 'codesphere.groups';

    public const SESSION_FETCHED_AT_KEY = 'codesphere.fetched_at';

    /**
     * Build the URL the browser should be redirected to in order to begin
     * the OAuth authorization code flow.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $base = rtrim((string) config('codesphere.url'), '/');

        return $base.'/oauth/authorize?'.http_build_query([
            'client_id' => config('codesphere.client_id'),
            'redirect_uri' => config('codesphere.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', (array) config('codesphere.scopes', [])),
            'state' => $state,
        ]);
    }

    /**
     * Exchange an authorization code for an access token + refresh token.
     *
     * @return array{access_token:string,refresh_token?:string,expires_in?:int,token_type?:string}
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = $this->client()->asForm()->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('codesphere.client_id'),
            'client_secret' => config('codesphere.client_secret'),
            'redirect_uri' => config('codesphere.redirect_uri'),
            'code' => $code,
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Use a refresh token to obtain a new access token.
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = $this->client()->asForm()->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => config('codesphere.client_id'),
            'client_secret' => config('codesphere.client_secret'),
            'refresh_token' => $refreshToken,
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Fetch the authenticated user's profile from CodeSphere Accounts.
     * The response includes user data and the user's groups.
     *
     * @return array{user:array,groups?:array}
     */
    public function fetchUserProfile(string $accessToken): array
    {
        $response = $this->client()
            ->withToken($accessToken)
            ->get('/api/user/profile');

        $response->throw();

        return $response->json();
    }

    /**
     * Fetch the members of a group from CodeSphereAccounts. Used by
     * consumer apps that want to show a read-only "who is on this
     * team" panel without re-implementing the data model locally.
     *
     * @param  Authenticatable  $user  the current user, used to get a
     *                                 valid bearer token
     * @return array<int, array<string, mixed>> list of
     *                                          { user_id, name, email, avatar,
     *                                          role, status, joined_at }
     */
    public function fetchGroupMembers(Authenticatable $user, int|string $groupId): array
    {
        $token = $this->getValidAccessToken($user);

        if (! $token) {
            return [];
        }

        try {
            $response = $this->client()
                ->withToken($token)
                ->get('/api/groups/'.$groupId.'/members');
        } catch (RequestException $e) {
            Log::warning('CodeSphere: fetchGroupMembers failed', [
                'group' => $groupId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($response->failed()) {
            return [];
        }

        return (array) $response->json();
    }

    /**
     * Cache the user profile + groups returned by CodeSphere Accounts in
     * the current session. This is the ONLY place user data lives locally.
     */
    public function cacheProfile(array $profile): void
    {
        Session::put(self::SESSION_USER_KEY, $profile['user'] ?? $profile);
        Session::put(self::SESSION_GROUPS_KEY, $profile['groups'] ?? []);
        Session::put(self::SESSION_FETCHED_AT_KEY, now()->timestamp);
    }

    /**
     * Clear all cached CodeSphere data from the session.
     */
    public function clearCache(): void
    {
        Session::forget([
            self::SESSION_USER_KEY,
            self::SESSION_GROUPS_KEY,
            self::SESSION_FETCHED_AT_KEY,
        ]);
    }

    /**
     * Get the cached user profile from the session.
     */
    public function user(): ?array
    {
        return Session::get(self::SESSION_USER_KEY);
    }

    /**
     * A specific field from the cached user profile.
     */
    public function userField(string $key, mixed $default = null): mixed
    {
        return $this->user()[$key] ?? $default;
    }

    /**
     * Get the user's groups from session, filtered by the configured
     * allowed_account_types. Returns a Collection of group arrays.
     */
    public function groups(): Collection
    {
        $groups = collect(Session::get(self::SESSION_GROUPS_KEY, []));

        $allowedTypes = (array) config('codesphere.allowed_account_types', []);
        if (! empty($allowedTypes)) {
            $groups = $groups->filter(
                fn ($g) => in_array($g['account_type'] ?? null, $allowedTypes, true)
            );
        }

        return $groups->values();
    }

    /**
     * Find a single group by its CodeSphere id.
     */
    public function findGroup(int|string $codesphereGroupId): ?array
    {
        return $this->groups()->firstWhere('id', $codesphereGroupId);
    }

    /**
     * The current user's role in a group, or null if not a member.
     * Returns one of: 'owner', 'admin', 'member', 'guest'.
     */
    public function roleInGroup(int|string $codesphereGroupId): ?string
    {
        return $this->findGroup($codesphereGroupId)['role'] ?? null;
    }

    public function isMemberOfGroup(int|string $codesphereGroupId): bool
    {
        return $this->roleInGroup($codesphereGroupId) !== null;
    }

    public function isOwnerOfGroup(int|string $codesphereGroupId): bool
    {
        return $this->roleInGroup($codesphereGroupId) === 'owner';
    }

    public function canManageGroup(int|string $codesphereGroupId): bool
    {
        return in_array(
            $this->roleInGroup($codesphereGroupId),
            ['owner', 'admin'],
            true
        );
    }

    /**
     * Refresh the session-cached user data if the cache is stale.
     */
    public function refreshIfStale(?Authenticatable $user): void
    {
        if (! $user) {
            return;
        }

        $fetchedAt = (int) Session::get(self::SESSION_FETCHED_AT_KEY, 0);
        $ttl = (int) config('codesphere.cache_ttl', 900);

        if ($fetchedAt > 0 && (now()->timestamp - $fetchedAt) < $ttl) {
            return;
        }

        $this->refreshUserData($user);
    }

    /**
     * Force-refresh the user's profile and groups from CodeSphere Accounts
     * and update the session cache.
     */
    public function refreshUserData(Authenticatable $user): void
    {
        $token = $this->getValidAccessToken($user);

        if (! $token) {
            return;
        }

        try {
            $profile = $this->fetchUserProfile($token);
            $this->cacheProfile($profile);
        } catch (RequestException $e) {
            Log::warning('CodeSphere: failed to refresh user data', [
                'user_id' => $user->getAuthIdentifier(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return a valid access token for the user, refreshing it via the
     * refresh token if it has expired. Returns null if no valid token
     * can be obtained (the user should be logged out).
     */
    public function getValidAccessToken(?Authenticatable $user): ?string
    {
        if (! $user) {
            return null;
        }

        if ($user->token_expires_at && $user->token_expires_at->isFuture()) {
            return $user->access_token;
        }

        if (! $user->refresh_token) {
            return null;
        }

        try {
            $tokenData = $this->refreshAccessToken($user->refresh_token);
        } catch (RequestException $e) {
            Log::warning('CodeSphere: token refresh failed', [
                'user_id' => $user->getAuthIdentifier(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $user->forceFill([
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? $user->refresh_token,
            'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 0),
        ])->save();

        return $user->access_token;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('codesphere.url'), '/'))
            ->acceptJson()
            ->timeout(10);
    }
}

<?php

namespace CodeSphere\OAuth\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired AFTER a successful CodeSphere OAuth login, after the user has been
 * authenticated and the profile has been cached in the session.
 *
 * Apps should listen for this event to perform their own post-login work
 * (e.g. syncing local Company/Team records, picking a default workspace).
 *
 * The event carries:
 *   - $user: the local Authenticatable user
 *   - $profile: the full profile payload from CodeSphereAccounts (user + groups)
 */
class CodeSphereLoggedIn
{
    use Dispatchable;

    public function __construct(
        public readonly Authenticatable $user,
        public readonly array $profile,
    ) {}

    /**
     * Convenience accessor for the groups returned with the profile.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groups(): array
    {
        return $this->profile['groups'] ?? [];
    }

    /**
     * Convenience accessor for the user payload from the profile.
     *
     * @return array<string, mixed>
     */
    public function userData(): array
    {
        return $this->profile['user'] ?? $this->profile;
    }
}

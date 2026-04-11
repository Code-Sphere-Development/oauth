<?php

namespace CodeSphere\OAuth\Concerns;

use CodeSphere\OAuth\Services\CodeSphereService;
use Illuminate\Support\Collection;

/**
 * Adds CodeSphere identity helpers to a local User model.
 *
 * IMPORTANT: This trait expects the local users table to have only:
 *   - id
 *   - codesphere_id (unique, references the CodeSphereAccounts user id)
 *   - access_token (encrypted)
 *   - refresh_token (encrypted)
 *   - token_expires_at
 *
 * No name, email, avatar, or other profile data should be persisted.
 * Display attributes (name, email, avatar, account_type) are read from the
 * session cache populated by CodeSphereService::cacheProfile().
 *
 * The trait exposes the profile attributes as Eloquent magic accessors so
 * you can use $user->name, $user->email, etc. as usual in views.
 */
trait HasCodeSphereIdentity
{
    /**
     * Eloquent magic accessor: $user->name
     */
    public function getNameAttribute(): ?string
    {
        return $this->codeSphere()->userField('name');
    }

    /**
     * Eloquent magic accessor: $user->email
     */
    public function getEmailAttribute(): ?string
    {
        return $this->codeSphere()->userField('email');
    }

    /**
     * Eloquent magic accessor: $user->avatar
     */
    public function getAvatarAttribute(): ?string
    {
        return $this->codeSphere()->userField('avatar');
    }

    /**
     * Eloquent magic accessor: $user->account_type
     */
    public function getAccountTypeAttribute(): ?string
    {
        return $this->codeSphere()->userField('account_type');
    }

    /**
     * Get the user's CodeSphere groups (from session cache).
     */
    public function codeSphereGroups(): Collection
    {
        return $this->codeSphere()->groups();
    }

    /**
     * Get a valid access token for this user, refreshing if expired.
     */
    public function getValidAccessToken(): ?string
    {
        return $this->codeSphere()->getValidAccessToken($this);
    }

    /**
     * The local users table has no password column. Return an empty string
     * so Authenticatable::getAuthPassword() doesn't blow up.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /**
     * The local users table has no remember_token column either.
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // no-op
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    protected function codeSphere(): CodeSphereService
    {
        return app(CodeSphereService::class);
    }
}

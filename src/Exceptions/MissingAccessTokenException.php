<?php

namespace CodeSphere\OAuth\Exceptions;

use RuntimeException;

/**
 * No valid CodeSphere access token could be obtained for the user (no
 * tokens stored, or the refresh failed). CodeSphereService::accessRevoked()
 * tells whether CodeSphere Accounts rejected the refresh token.
 */
class MissingAccessTokenException extends RuntimeException {}

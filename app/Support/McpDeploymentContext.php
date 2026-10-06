<?php

declare(strict_types=1);

namespace App\Support;

final class McpDeploymentContext
{
    private const string AUDIENCE_PREFIX = 'urn:witness:deployment:';

    public static function audience(mixed $managedAudience, mixed $appKey): ?string
    {
        if (is_string($managedAudience) && $managedAudience !== '') {
            return $managedAudience;
        }

        if (! is_string($appKey) || $appKey === '') {
            return null;
        }

        return self::AUDIENCE_PREFIX.hash_hmac('sha256', 'witness:mcp:deployment:v1', $appKey);
    }

    public static function delegated(mixed $managedIssuer, mixed $managedAudience): bool
    {
        return is_string($managedIssuer) && $managedIssuer !== ''
            && is_string($managedAudience) && $managedAudience !== '';
    }
}

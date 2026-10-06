<?php

declare(strict_types=1);

namespace App\Authorization;

use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;

final class McpAccess
{
    public function user(WitnessMcpAbility $ability): ?User
    {
        $request = app('request');

        if (! $request instanceof Request) {
            return null;
        }

        $credential = $request->user();

        if (! $credential instanceof Credential
            || $credential->purpose !== CredentialPurpose::Mcp
            || $credential->status !== CredentialStatus::Active
            || $credential->revoked_at !== null
            || $credential->expires_at?->isPast()
            || ! $credential->hasAbility($ability->value)
            || $credential->user_id === null
            || filter_var($credential->user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return null;
        }

        $user = User::query()
            ->whereKey((int) $credential->user_id)
            ->where('status', 'active')
            ->whereNull('deactivated_at')
            ->first();

        return $user instanceof User && RolePolicy::canUseProduct($user->role) ? $user : null;
    }
}

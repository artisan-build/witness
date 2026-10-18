<?php

declare(strict_types=1);

namespace Tests\Support;

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;

final class ManagedMcpContext
{
    public const string CONNECTION_ID = 'witness-managed-mcp-test';

    public static function configure(string $issuer): void
    {
        $authority = DB::table('bfc_authority')
            ->where('key', InstallationAuthority::KEY)
            ->first(['generation']);

        DB::table('bfc_authority')
            ->where('key', InstallationAuthority::KEY)
            ->update([
                'mode' => AuthorityMode::Managed->value,
                'generation' => ((int) $authority->generation) + 1,
                'issuer' => $issuer,
                'connection_id' => self::CONNECTION_ID,
                'organization_id' => 'witness-managed-mcp-organization',
                'installation_id' => 'witness-managed-mcp-installation',
                'authority_base_url' => 'https://authority.witness.test',
                'managed_connection_status' => 'active',
                'updated_at' => now(),
            ]);

        config([
            'built-for-cloud.managed.client_secret' => 'witness-managed-mcp-secret',
            'built-for-cloud.managed.ca_bundle' => null,
        ]);
    }

    public static function restoreStandaloneAuthority(): void
    {
        $authority = DB::table('bfc_authority')
            ->where('key', InstallationAuthority::KEY)
            ->first(['generation']);

        DB::table('bfc_authority')
            ->where('key', InstallationAuthority::KEY)
            ->update([
                'mode' => AuthorityMode::Standalone->value,
                'generation' => ((int) $authority->generation) + 1,
                'updated_at' => now(),
            ]);
    }

    /** @param array<string, mixed> $attributes */
    public static function user(string $issuer, string $subject, array $attributes = []): User
    {
        $user = UserFactory::new()->create();
        $user->forceFill([
            'scalpels_issuer' => $issuer,
            'scalpels_connection_id' => self::CONNECTION_ID,
            'scalpels_id' => $subject,
            'managed_membership_status' => 'active',
            'membership_confirmed_at' => now(),
            ...$attributes,
        ])->save();

        return $user->refresh();
    }
}

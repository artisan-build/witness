<?php

declare(strict_types=1);

namespace App\Providers;

use App\Authorization\WitnessMcpAbility;
use App\Mcp\WitnessMcpServer;
use ArtisanBuild\BuiltForCloud\CredentialAbilityRegistry;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

final class WitnessMcpServiceProvider extends ServiceProvider
{
    public function boot(CredentialAbilityRegistry $abilities): void
    {
        $abilities->register(
            WitnessMcpAbility::Read->value,
            WitnessMcpAbility::Destructive->value,
        );

        Mcp::web('/mcp', WitnessMcpServer::class)
            ->middleware('bfc.mcp:product,read');
        Mcp::web('/mcp/destructive', WitnessMcpServer::class)
            ->middleware('bfc.mcp:product,destructive');
    }
}

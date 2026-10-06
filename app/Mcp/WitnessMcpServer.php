<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\EnvelopeStatus;
use App\Mcp\Tools\ListEnvelopes;
use App\Mcp\Tools\ListTemplates;
use App\Mcp\Tools\Remind;
use App\Mcp\Tools\SendFromTemplate;
use App\Mcp\Tools\VoidEnvelope;
use Laravel\Mcp\Server;

final class WitnessMcpServer extends Server
{
    protected string $name = 'Witness';

    protected string $version = '1.0.0';

    protected string $instructions = 'Read bounded signing workflow status or preview and confirm sender-owned envelope actions.';

    protected array $tools = [
        ListTemplates::class,
        ListEnvelopes::class,
        EnvelopeStatus::class,
        SendFromTemplate::class,
        Remind::class,
        VoidEnvelope::class,
    ];
}

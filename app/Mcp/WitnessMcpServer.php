<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\EnvelopeStatus;
use App\Mcp\Tools\ListEnvelopes;
use App\Mcp\Tools\ListTemplates;
use App\Mcp\Tools\Remind;
use App\Mcp\Tools\SendFromTemplate;
use App\Mcp\Tools\VoidEnvelope;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseCallTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;

final class WitnessMcpServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->addMethod('tools/call', TwoPhaseCallTool::class);
    }

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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\McpDeploymentContextRouteTestCase;

final class DefaultMcpDeploymentContextTest extends McpDeploymentContextRouteTestCase
{
    protected function consoleEnvironment(): array
    {
        return [
            'BUILT_FOR_CLOUD_CONSOLE_ISSUER' => '',
            'BUILT_FOR_CLOUD_CONSOLE_AUDIENCE' => '',
        ];
    }
}

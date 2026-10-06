<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\McpDeploymentContextRouteTestCase;

final class ManagedMcpDeploymentContextTest extends McpDeploymentContextRouteTestCase
{
    protected function consoleEnvironment(): array
    {
        return [
            'BUILT_FOR_CLOUD_CONSOLE_ISSUER' => 'https://console.managed.test',
            'BUILT_FOR_CLOUD_CONSOLE_AUDIENCE' => 'managed-deployment-test',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use ArtisanBuild\BuiltForCloud\Console\Assertion;
use ArtisanBuild\BuiltForCloud\Console\AssertionPurpose;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublishesDelegatedAssertion
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $assertion = Assertion::fromVerifiedClaims(
            issuer: 'https://console.example.test',
            subject: 'delegated-sender-test',
            displayName: 'Delegated sender test',
            role: ConsoleRole::Admin,
            onBehalfOf: 'Test team',
            audience: 'witness-test',
            issuedAt: now()->toImmutable(),
            expiresAt: now()->addMinute()->toImmutable(),
            keyId: 'test-key',
            id: 'test-'.bin2hex(random_bytes(8)),
            purpose: AssertionPurpose::Mcp,
        );
        $actor = DelegatedActor::recordHandoff($assertion);
        RequestAssertion::publish($request, $actor, $assertion);

        return $next($request);
    }
}

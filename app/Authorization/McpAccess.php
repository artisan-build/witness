<?php

declare(strict_types=1);

namespace App\Authorization;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Exceptions\DelegatedPrincipalUnsupported;
use ArtisanBuild\BuiltForCloud\Exceptions\ManagedAuthRefused;
use ArtisanBuild\BuiltForCloud\Exceptions\NoActingPrincipal;
use ArtisanBuild\BuiltForCloud\ManagedAccountAccess;
use ArtisanBuild\BuiltForCloud\ManagedAuthConnection;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;

final readonly class McpAccess
{
    public function __construct(
        private Request $request,
        private ActingPrincipalResolver $principals,
        private ManagedAccountAccess $managedAccess,
    ) {}

    public function user(WitnessMcpAbility $ability): ?User
    {
        try {
            $acting = $this->principals->require($this->request);
        } catch (DelegatedPrincipalUnsupported|NoActingPrincipal) {
            return null;
        }

        if ($acting->delegatedActor instanceof DelegatedActor) {
            return $this->delegatedUser($acting->delegatedActor);
        }

        $credential = $acting->principal;

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

        $user = $this->principals->localUser($this->request);

        return $user instanceof User && $this->allows($user) ? $user : null;
    }

    private function delegatedUser(DelegatedActor $actor): ?User
    {
        try {
            $connection = ManagedAuthConnection::current();
        } catch (ManagedAuthRefused) {
            return null;
        }

        if (! hash_equals($connection->issuer, $actor->issuer)) {
            return null;
        }

        $user = User::query()
            ->where('scalpels_issuer', $connection->issuer)
            ->where('scalpels_connection_id', $connection->connectionId)
            ->where('scalpels_id', $actor->subject)
            ->first();

        return $user instanceof User && $this->allows($user) ? $user : null;
    }

    private function allows(User $user): bool
    {
        return $user->status === 'active'
            && $user->deactivated_at === null
            && RolePolicy::canUseProduct($user->role)
            && ! OffboardedSubject::userOrSubjectIsOffboarded($this->request, $user)
            && $this->managedAccess->allows($user);
    }
}

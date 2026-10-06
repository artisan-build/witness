<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\RemindEnvelope;
use App\Authorization\WitnessMcpAbility;
use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Models\Envelope;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhase;
use DomainException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

#[IsDestructive]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Destructive)]
#[TwoPhase]
final class Remind extends WitnessTool
{
    protected string $name = 'remind';

    protected string $description = 'Rotate and send invitations for eligible nonterminal signers on one caller-owned envelope.';

    public function __construct(private readonly RemindEnvelope $remindEnvelope) {}

    public function schema(JsonSchema $schema): array
    {
        return ['envelope_id' => $schema->integer()->min(1)->required()];
    }

    public function preview(Request $request): Response|ResponseFactory
    {
        $user = $this->access()->user(WitnessMcpAbility::Destructive);
        if ($user === null) {
            return Response::error('Witness destructive access is required.');
        }

        $data = $this->validated($request);
        $envelope = Envelope::query()
            ->whereKey($data['envelope_id'])
            ->where('sender_id', $user->getKey())
            ->whereHas('team.users', fn ($query) => $query->whereKey($user->getKey()))
            ->where('status', EnvelopeStatus::Sent->value)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->withCount(['signers as eligible_signer_count' => fn ($query) => $query
                ->whereNotIn('status', [SignerStatus::Signed->value, SignerStatus::Declined->value])])
            ->first();

        if (! $envelope instanceof Envelope) {
            return Response::error('Envelope not found or cannot be reminded.');
        }

        return Response::structured([
            'operation' => 'remind',
            'envelope_id' => (int) $envelope->getKey(),
            'title' => $envelope->title,
            'eligible_signer_count' => (int) $envelope->getAttribute('eligible_signer_count'),
        ]);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->access()->user(WitnessMcpAbility::Destructive);
        if ($user === null) {
            return Response::error('Witness destructive access is required.');
        }

        $data = $this->validated($request);

        try {
            $result = $this->remindEnvelope->handle(
                $user,
                $data['envelope_id'],
                request()->ip(),
                request()->userAgent(),
            );
        } catch (ModelNotFoundException|DomainException) {
            return Response::error('Envelope not found or cannot be reminded.');
        } catch (Throwable) {
            return Response::error('The reminder operation could not be completed safely.');
        }

        return Response::structured(['envelope_id' => $data['envelope_id'], ...$result]);
    }

    /** @return array{envelope_id: int} */
    private function validated(Request $request): array
    {
        $this->validateOnly($request, ['envelope_id']);

        /** @var array{envelope_id: int} */
        return $request->validate(['envelope_id' => ['required', 'integer', 'min:1']]);
    }
}

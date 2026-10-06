<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\VoidEnvelope as VoidEnvelopeAction;
use App\Authorization\WitnessMcpAbility;
use App\Enums\EnvelopeStatus;
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
final class VoidEnvelope extends WitnessTool
{
    protected string $name = 'void';

    protected string $description = 'Void one caller-owned active envelope with a bounded plain-text reason.';

    public function __construct(private readonly VoidEnvelopeAction $voidEnvelope) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'envelope_id' => $schema->integer()->min(1)->required(),
            'reason' => $schema->string()->min(1)->max(1000)->required(),
        ];
    }

    public function preview(Request $request): Response|ResponseFactory
    {
        $user = $this->access()->user(WitnessMcpAbility::Destructive);
        if ($user === null) {
            return Response::error('Witness destructive access is required.');
        }

        $data = $this->validated($request);
        $envelope = $this->findEnvelope($user->getKey(), $data['envelope_id']);

        if (! $envelope instanceof Envelope || ! in_array($envelope->status, [EnvelopeStatus::Sent, EnvelopeStatus::Voided], true)) {
            return Response::error('Envelope not found or cannot be voided.');
        }

        return Response::structured([
            'operation' => 'void',
            'envelope_id' => (int) $envelope->getKey(),
            'title' => $envelope->title,
            'current_status' => $envelope->status->value,
            'reason_length' => mb_strlen(trim($data['reason'])),
        ]);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->access()->user(WitnessMcpAbility::Destructive);
        if ($user === null) {
            return Response::error('Witness destructive access is required.');
        }

        $data = $this->validated($request);
        $target = $this->findEnvelope($user->getKey(), $data['envelope_id']);
        if (! $target instanceof Envelope) {
            return Response::error('Envelope not found or cannot be voided.');
        }
        $changed = $target->status === EnvelopeStatus::Sent;

        try {
            $envelope = $this->voidEnvelope->handle($target->team, $user, $data['envelope_id'], $data['reason']);
        } catch (ModelNotFoundException|DomainException) {
            return Response::error('Envelope not found or cannot be voided.');
        } catch (Throwable) {
            return Response::error('The envelope could not be voided safely.');
        }

        return Response::structured([
            'envelope_id' => (int) $envelope->getKey(),
            'status' => $envelope->status->value,
            'changed' => $changed,
            'sender_notification' => $changed ? 'attempted_delivery_unknown' : 'not_attempted',
        ]);
    }

    /** @return array{envelope_id: int, reason: string} */
    private function validated(Request $request): array
    {
        $this->validateOnly($request, ['envelope_id', 'reason']);

        /** @var array{envelope_id: int, reason: string} */
        return $request->validate([
            'envelope_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:1000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
        ]);
    }

    private function findEnvelope(int|string $userId, int $envelopeId): ?Envelope
    {
        return Envelope::query()
            ->whereKey($envelopeId)
            ->where('sender_id', $userId)
            ->whereHas('team.users', fn ($query) => $query->whereKey($userId))
            ->with('team')
            ->first();
    }
}

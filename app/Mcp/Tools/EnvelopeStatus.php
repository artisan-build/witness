<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\WitnessMcpAbility;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Signer;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class EnvelopeStatus extends WitnessTool
{
    protected string $name = 'envelope_status';

    protected string $description = 'Return bounded envelope, signer, and ordered public audit status for one caller-owned envelope.';

    public function schema(JsonSchema $schema): array
    {
        return ['envelope_id' => $schema->integer()->min(1)->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->access()->user(WitnessMcpAbility::Read);
        if ($user === null) {
            return Response::error('Witness read access is required.');
        }

        $this->validateOnly($request, ['envelope_id']);
        $data = $request->validate(['envelope_id' => ['required', 'integer', 'min:1']]);
        $envelope = Envelope::query()
            ->whereKey($data['envelope_id'])
            ->where('sender_id', $user->getKey())
            ->whereHas('team.users', fn ($query) => $query->whereKey($user->getKey()))
            ->with([
                'signers' => fn ($query) => $query->orderBy('position')->orderBy('id'),
                'auditEvents' => fn ($query) => $query->limit(501),
            ])
            ->first();

        if (! $envelope instanceof Envelope) {
            return Response::error('Envelope not found.');
        }

        return Response::structured([
            'envelope' => [
                'id' => (int) $envelope->getKey(),
                'team_id' => (int) $envelope->team_id,
                'title' => $envelope->title,
                'status' => $envelope->status->value,
                'sent_at' => $envelope->sent_at?->toIso8601String(),
                'expires_at' => $envelope->expires_at?->toIso8601String(),
                'completed_at' => $envelope->completed_at?->toIso8601String(),
            ],
            'signers' => $envelope->signers->map(fn (Signer $signer): array => [
                'id' => (int) $signer->getKey(),
                'name' => $signer->name,
                'email' => $signer->email,
                'role' => $signer->role_label,
                'status' => $signer->status->value,
                'viewed_at' => $signer->viewed_at?->toIso8601String(),
                'signed_at' => $signer->signed_at?->toIso8601String(),
                'declined_at' => $signer->declined_at?->toIso8601String(),
            ])->values()->all(),
            'audit_events' => $envelope->auditEvents->take(500)->map(fn (AuditEvent $event): array => [
                'sequence' => $event->sequence,
                'type' => $event->type->value,
                'actor_type' => $event->actor_type->value,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ])->values()->all(),
            'audit_events_truncated' => $envelope->auditEvents->count() > 500,
        ]);
    }
}

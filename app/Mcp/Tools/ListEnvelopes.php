<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\WitnessMcpAbility;
use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class ListEnvelopes extends WitnessTool
{
    protected string $name = 'list_envelopes';

    protected string $description = 'List the caller-owned envelopes, optionally filtered by status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(array_column(EnvelopeStatus::cases(), 'value')),
            'limit' => $schema->integer()->min(1)->max(100),
            'after_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->access()->user(WitnessMcpAbility::Read);
        if ($user === null) {
            return Response::error('Witness read access is required.');
        }

        $this->validateOnly($request, ['status', 'limit', 'after_id']);
        $data = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(EnvelopeStatus::class)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'after_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $limit = (int) ($data['limit'] ?? 50);

        $envelopes = Envelope::query()
            ->select(['id', 'team_id', 'title', 'status', 'sent_at', 'expires_at', 'completed_at', 'created_at', 'updated_at'])
            ->where('sender_id', $user->getKey())
            ->whereHas('team.users', fn ($query) => $query->whereKey($user->getKey()))
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(isset($data['after_id']), fn ($query) => $query->where('id', '>', $data['after_id']))
            ->withCount('signers')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $envelopes->count() > $limit;
        $envelopes = $envelopes->take($limit);

        return Response::structured([
            'envelopes' => $envelopes->map(fn (Envelope $envelope): array => [
                'id' => (int) $envelope->getKey(),
                'team_id' => (int) $envelope->team_id,
                'title' => $envelope->title,
                'status' => $envelope->status->value,
                'signer_count' => $envelope->signers_count,
                'sent_at' => $envelope->sent_at?->toIso8601String(),
                'expires_at' => $envelope->expires_at?->toIso8601String(),
                'completed_at' => $envelope->completed_at?->toIso8601String(),
                'created_at' => $envelope->created_at?->toIso8601String(),
                'updated_at' => $envelope->updated_at?->toIso8601String(),
            ])->values()->all(),
            'next_after_id' => $hasMore ? (int) $envelopes->last()?->getKey() : null,
        ]);
    }
}

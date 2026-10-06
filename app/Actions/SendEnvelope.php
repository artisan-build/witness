<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Models\Envelope;
use App\Models\Team;
use App\Services\EnvelopeSendValidator;
use ArtisanBuild\BuiltForCloud\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SendEnvelope
{
    public function __construct(private readonly EnvelopeSendValidator $validator) {}

    public function handle(
        Team $team,
        User $sender,
        int $envelopeId,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Envelope {
        if (! $team->hasMember($sender)) {
            throw new DomainException('The sender must belong to the envelope team.');
        }

        return DB::transaction(function () use ($team, $sender, $envelopeId, $ipAddress, $userAgent): Envelope {
            $envelope = Envelope::query()
                ->forTeam($team)
                ->where('sender_id', $sender->getKey())
                ->lockForUpdate()
                ->findOrFail($envelopeId);

            if ($envelope->status !== EnvelopeStatus::Draft) {
                throw new DomainException('Only a draft envelope can be sent.');
            }

            $envelope->load(['document', 'signers.fields', 'fields']);
            $this->validator->assertSendable($envelope);

            DB::statement("SET LOCAL witness.envelope_transition = 'on'");
            DB::table('envelopes')->where('id', $envelope->getKey())->update([
                'status' => EnvelopeStatus::Sent,
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
            DB::statement("SET LOCAL witness.envelope_transition = 'off'");
            $envelope->refresh()->load('document');

            EnvelopeLifecycleRecorded::record(
                envelope: $envelope,
                type: AuditEventType::Created,
                actorType: AuditActorType::Sender,
                actorId: (int) $sender->getKey(),
                ipAddress: $envelope->created_ip_address,
                userAgent: $envelope->created_user_agent,
                payload: ['title' => $envelope->title],
                occurredAt: $envelope->created_at->toImmutable(),
            );

            EnvelopeLifecycleRecorded::record(
                envelope: $envelope,
                type: AuditEventType::Sent,
                actorType: AuditActorType::Sender,
                actorId: (int) $sender->getKey(),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                payload: ['signer_count' => $envelope->signers()->count()],
            );

            return $envelope->refresh()->load('document');
        });
    }
}

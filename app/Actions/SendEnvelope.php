<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Mail\SigningInvitation;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Team;
use App\Services\EnvelopeSendValidator;
use ArtisanBuild\BuiltForCloud\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final readonly class SendEnvelope
{
    public function __construct(private EnvelopeSendValidator $validator) {}

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

            $envelope->load(['document', 'sender', 'signers.fields', 'fields']);
            $this->validator->assertSendable($envelope);

            /** @var list<array{signer: Signer, token: string}> $invitations */
            $invitations = [];

            DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
            foreach ($envelope->signers as $signer) {
                $token = bin2hex(random_bytes(32));
                Signer::query()->whereKey($signer)->update([
                    'link_token_hash' => hash('sha256', $token),
                    'updated_at' => now(),
                ]);
                $invitations[] = ['signer' => $signer->refresh(), 'token' => $token];
            }
            DB::statement("SET LOCAL witness.signer_progress_write = 'off'");

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

            foreach ($invitations as $invitation) {
                Mail::to($invitation['signer']->email)->send(new SigningInvitation(
                    $envelope,
                    $invitation['signer'],
                    $invitation['token'],
                ));

                EnvelopeLifecycleRecorded::record(
                    envelope: $envelope,
                    type: AuditEventType::EmailSent,
                    actorType: AuditActorType::System,
                    payload: [
                        'purpose' => 'signing_invitation',
                        'signer_id' => $invitation['signer']->getKey(),
                    ],
                );
            }

            return $envelope->refresh()->load(['document', 'sender']);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MailDeliveryPurpose;
use App\Events\EnvelopeLifecycleRecorded;
use App\Mail\SigningInvitation;
use App\Models\Envelope;
use App\Models\MailDelivery;
use App\Models\Signer;
use App\Models\Team;
use App\Services\EnvelopeSendValidator;
use App\Services\OutboundMailDelivery;
use ArtisanBuild\BuiltForCloud\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final readonly class SendEnvelope
{
    public function __construct(
        private EnvelopeSendValidator $validator,
        private OutboundMailDelivery $mailDelivery,
    ) {}

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

        [$envelope, $invitations] = DB::transaction(function () use ($team, $sender, $envelopeId, $ipAddress, $userAgent): array {
            $envelope = Envelope::query()
                ->forTeam($team)
                ->where('sender_id', $sender->getKey())
                ->lockForUpdate()
                ->findOrFail($envelopeId);

            if ($envelope->status !== EnvelopeStatus::Draft) {
                throw new DomainException('Only a draft envelope can be sent.');
            }

            $envelope->load(['document', 'sender', 'signers.fields', 'fields']);
            $lockedSigners = Signer::query()
                ->where('envelope_id', $envelope->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->load('fields');
            $envelope->setRelation('signers', $lockedSigners);
            $this->validator->assertSendable($envelope);

            /** @var list<array{signer: Signer, token: string, delivery: MailDelivery}> $invitations */
            $invitations = [];

            DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
            foreach ($envelope->signers as $signer) {
                $token = bin2hex(random_bytes(32));
                Signer::query()->whereKey($signer)->update([
                    'link_token_hash' => hash('sha256', $token),
                    'updated_at' => now(),
                ]);
                $signer->refresh();
                $invitations[] = [
                    'signer' => $signer,
                    'token' => $token,
                    'delivery' => $this->mailDelivery->create(
                        $envelope,
                        $signer,
                        MailDeliveryPurpose::SigningInvitation,
                    ),
                ];
            }
            DB::statement("SET LOCAL witness.signer_progress_write = 'off'");

            DB::statement("SET LOCAL witness.envelope_transition = 'on'");
            DB::table('envelopes')->where('id', $envelope->getKey())->update([
                'status' => EnvelopeStatus::Sent,
                'sent_at' => now(),
                'expires_at' => $envelope->expires_at ?? now()->addDays(30),
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

            return [$envelope->refresh()->load(['document', 'sender']), $invitations];
        });

        foreach ($invitations as $invitation) {
            $this->mailDelivery->deliver(
                $invitation['delivery'],
                function () use ($envelope, $invitation): void {
                    Mail::to($invitation['signer']->email)->send(new SigningInvitation(
                        $envelope,
                        $invitation['signer'],
                        $invitation['token'],
                    ));
                },
                AuditEventType::EmailSent,
                AuditActorType::System,
                payload: ['signer_id' => $invitation['signer']->getKey()],
            );
        }

        return $envelope;
    }
}

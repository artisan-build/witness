<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Enums\SignerStatus;
use App\Mail\SigningInvitation;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Team;
use App\Services\OutboundMailDelivery;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
use ArtisanBuild\BuiltForCloud\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final readonly class RotateSigningInvitation
{
    public function __construct(
        private OutboundMailDelivery $mailDelivery,
        private SystemAuthorityContext $systemAuthority,
    ) {}

    public function handle(Team $team, ?User $sender, int $envelopeId, int $signerId, ?string $ipAddress = null, ?string $userAgent = null): bool
    {
        if ($sender !== null && ! $team->hasMember($sender)) {
            throw new DomainException('The sender must belong to the envelope team.');
        }
        if ($sender === null && ! $this->systemAuthority->active()) {
            throw new DomainException('Scheduled reminders require active system authority.');
        }

        $result = DB::transaction(function () use ($team, $sender, $envelopeId, $signerId): ?array {
            $envelope = Envelope::query()
                ->forTeam($team)
                ->when($sender !== null, fn ($query) => $query->where('sender_id', $sender->getKey()))
                ->lockForUpdate()
                ->findOrFail($envelopeId);
            $signer = Signer::query()
                ->where('envelope_id', $envelope->getKey())
                ->lockForUpdate()
                ->findOrFail($signerId);

            $scheduled = $sender === null;
            if ($envelope->status !== EnvelopeStatus::Sent || $envelope->expires_at?->isPast()
                || in_array($signer->status, [SignerStatus::Signed, SignerStatus::Declined], true)) {
                if ($scheduled) {
                    return null;
                }
                throw new DomainException('Only a non-terminal signer on a sent envelope may receive a new link.');
            }
            if ($scheduled) {
                $anchor = $envelope->last_reminded_at ?? $envelope->sent_at;
                if ($envelope->reminder_interval_days === null || $anchor === null
                    || $anchor->addDays((int) $envelope->reminder_interval_days)->isFuture()) {
                    return null;
                }
                $latest = $signer->mailDeliveries()
                    ->where('purpose', MailDeliveryPurpose::SigningInvitation->value)
                    ->orderByDesc('generation')
                    ->lockForUpdate()
                    ->first();
                if ($latest === null || $latest->status !== MailDeliveryStatus::Sent) {
                    return null;
                }
            }

            $token = bin2hex(random_bytes(32));
            try {
                DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
                Signer::query()->whereKey($signer)->update([
                    'link_token_hash' => hash('sha256', $token),
                    'updated_at' => now(),
                ]);
            } finally {
                DB::statement("SET LOCAL witness.signer_progress_write = 'off'");
            }
            $signer->refresh();

            return [
                $envelope->load('document'),
                $signer,
                $token,
                $this->mailDelivery->create($envelope, $signer, MailDeliveryPurpose::SigningInvitation),
            ];
        });
        if ($result === null) {
            return false;
        }
        [$envelope, $signer, $token, $delivery] = $result;

        $delivered = $this->mailDelivery->deliver(
            $delivery,
            fn () => Mail::to($signer->email)->send(new SigningInvitation($envelope, $signer, $token)),
            AuditEventType::Reminded,
            $sender === null ? AuditActorType::System : AuditActorType::Sender,
            actorId: $sender?->getKey(),
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            payload: ['signer_id' => $signer->getKey()],
        );

        if ($delivered && $sender !== null) {
            Envelope::query()->whereKey($envelope)->update(['last_reminded_at' => now()]);
        }

        return $delivered;
    }
}

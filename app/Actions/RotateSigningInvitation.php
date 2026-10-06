<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Mail\SigningInvitation;
use App\Models\Envelope;
use App\Models\MailDelivery;
use App\Models\Signer;
use App\Models\Team;
use App\Services\OutboundMailDelivery;
use ArtisanBuild\BuiltForCloud\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final readonly class RotateSigningInvitation
{
    public function __construct(private OutboundMailDelivery $mailDelivery) {}

    public function handle(Team $team, User $sender, int $signerId): bool
    {
        if (! $team->hasMember($sender)) {
            throw new DomainException('The sender must belong to the envelope team.');
        }

        [$envelope, $signer, $token, $delivery] = DB::transaction(function () use ($team, $sender, $signerId): array {
            $candidate = Signer::query()->findOrFail($signerId);
            $envelope = Envelope::query()
                ->forTeam($team)
                ->where('sender_id', $sender->getKey())
                ->lockForUpdate()
                ->findOrFail($candidate->envelope_id);
            $signer = Signer::query()->lockForUpdate()->findOrFail($signerId);
            $latest = MailDelivery::query()
                ->where('signer_id', $signer->getKey())
                ->where('purpose', MailDeliveryPurpose::SigningInvitation->value)
                ->latest('generation')
                ->lockForUpdate()
                ->first();

            if ($envelope->status !== EnvelopeStatus::Sent || $latest?->status !== MailDeliveryStatus::Unknown) {
                throw new DomainException('Only an invitation with unknown delivery may be rotated.');
            }

            $token = bin2hex(random_bytes(32));
            DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
            Signer::query()->whereKey($signer)->update([
                'link_token_hash' => hash('sha256', $token),
                'updated_at' => now(),
            ]);
            DB::statement("SET LOCAL witness.signer_progress_write = 'off'");
            $signer->refresh();

            return [
                $envelope->load('document'),
                $signer,
                $token,
                $this->mailDelivery->create($envelope, $signer, MailDeliveryPurpose::SigningInvitation),
            ];
        });

        return $this->mailDelivery->deliver(
            $delivery,
            fn () => Mail::to($signer->email)->send(new SigningInvitation($envelope, $signer, $token)),
            AuditEventType::EmailSent,
            AuditActorType::System,
            payload: ['signer_id' => $signer->getKey()],
        );
    }
}

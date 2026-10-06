<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\EnvelopeStatus;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Enums\SignerStatus;
use App\Mail\SigningDeclined;
use App\Models\Envelope;
use App\Models\MailDelivery;
use App\Models\Signer;
use App\Models\Team;
use App\Services\OutboundMailDelivery;
use ArtisanBuild\BuiltForCloud\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final readonly class RetryDeclineNotification
{
    public function __construct(private OutboundMailDelivery $mailDelivery) {}

    public function handle(Team $team, User $sender, int $signerId): bool
    {
        if (! $team->hasMember($sender)) {
            throw new DomainException('The sender must belong to the envelope team.');
        }

        [$envelope, $signer, $delivery] = DB::transaction(function () use ($team, $sender, $signerId): array {
            $candidate = Signer::query()->findOrFail($signerId);
            $envelope = Envelope::query()
                ->with('sender')
                ->forTeam($team)
                ->where('sender_id', $sender->getKey())
                ->lockForUpdate()
                ->findOrFail($candidate->envelope_id);
            $signer = Signer::query()->lockForUpdate()->findOrFail($signerId);
            $latest = MailDelivery::query()
                ->where('signer_id', $signer->getKey())
                ->where('purpose', MailDeliveryPurpose::DeclineNotification->value)
                ->latest('generation')
                ->lockForUpdate()
                ->first();

            if ($envelope->status !== EnvelopeStatus::Declined
                || $signer->status !== SignerStatus::Declined
                || $latest?->status !== MailDeliveryStatus::Unknown) {
                throw new DomainException('Only an unknown decline notification may be retried.');
            }

            return [
                $envelope,
                $signer,
                $this->mailDelivery->create($envelope, $signer, MailDeliveryPurpose::DeclineNotification),
            ];
        });

        return $this->mailDelivery->deliver(
            $delivery,
            fn () => Mail::to($envelope->sender->email)->send(new SigningDeclined(
                $envelope,
                $signer,
                (string) $signer->decline_reason,
            )),
            null,
            AuditActorType::System,
        );
    }
}

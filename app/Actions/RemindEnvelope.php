<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Models\Envelope;
use ArtisanBuild\BuiltForCloud\User;
use DomainException;

final readonly class RemindEnvelope
{
    public function __construct(private RotateSigningInvitation $rotateInvitation) {}

    /** @return array{targeted: int, sent: int, unknown: int} */
    public function handle(User $sender, int $envelopeId, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $envelope = Envelope::query()
            ->whereKey($envelopeId)
            ->where('sender_id', $sender->getKey())
            ->whereHas('team.users', fn ($query) => $query->whereKey($sender->getKey()))
            ->with(['team', 'signers' => fn ($query) => $query
                ->whereNotIn('status', [SignerStatus::Signed->value, SignerStatus::Declined->value])
                ->orderBy('id')])
            ->firstOrFail();

        if ($envelope->status !== EnvelopeStatus::Sent || $envelope->expires_at?->isPast()) {
            throw new DomainException('Only an active sent envelope can be reminded.');
        }

        $sent = 0;
        foreach ($envelope->signers as $signer) {
            $sent += (int) $this->rotateInvitation->handle(
                $envelope->team,
                $sender,
                (int) $envelope->getKey(),
                (int) $signer->getKey(),
                $ipAddress,
                $userAgent,
            );
        }

        return [
            'targeted' => $envelope->signers->count(),
            'sent' => $sent,
            'unknown' => $envelope->signers->count() - $sent,
        ];
    }
}

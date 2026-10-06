<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Mail\LifecycleNotification;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class VoidEnvelope
{
    public function handle(Team $team, User $sender, int $envelopeId, string $reason): Envelope
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000 || ! $team->hasMember($sender)) {
            throw new DomainException('A valid plain-text void reason and owning sender are required.');
        }

        [$envelope, $changed] = DB::transaction(function () use ($team, $sender, $envelopeId, $reason): array {
            $envelope = Envelope::query()->forTeam($team)->where('sender_id', $sender->getKey())
                ->lockForUpdate()->findOrFail($envelopeId);
            Signer::query()->where('envelope_id', $envelopeId)->orderBy('id')->lockForUpdate()->get();
            if ($envelope->status === EnvelopeStatus::Voided) {
                return [$envelope->load('sender'), false];
            }
            if ($envelope->status !== EnvelopeStatus::Sent) {
                throw new DomainException('Only an active sent envelope can be voided.');
            }

            EnvelopeLifecycleRecorded::record($envelope, AuditEventType::Voided, AuditActorType::Sender, $sender->getKey(), payload: ['reason' => $reason]);
            DB::statement("SET LOCAL witness.envelope_transition = 'on'");
            DB::table('envelopes')->where('id', $envelopeId)->update(['status' => EnvelopeStatus::Voided->value, 'updated_at' => now()]);

            return [$envelope->refresh()->load('sender'), true];
        });

        if ($changed) {
            try {
                Mail::mailer((string) config('mail.default'))->to($sender->email)->send(new LifecycleNotification($envelope, 'voided'));
            } catch (Throwable) {
                // The durable lifecycle result is authoritative when mail handoff is ambiguous.
            }
        }

        return $envelope;
    }
}

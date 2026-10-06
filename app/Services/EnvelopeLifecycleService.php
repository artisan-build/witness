<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\RotateSigningInvitation;
use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Mail\LifecycleNotification;
use App\Models\Envelope;
use App\Models\Signer;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

final readonly class EnvelopeLifecycleService
{
    public function __construct(
        private RotateSigningInvitation $rotateInvitation,
        private SystemAuthorityContext $systemAuthority,
    ) {}

    public function runDue(): void
    {
        if (! $this->systemAuthority->active()) {
            throw new DomainException('Scheduled lifecycle work requires active system authority.');
        }

        $this->expireDue();
        $this->remindDue();
    }

    private function expireDue(): void
    {
        Envelope::query()->where('status', EnvelopeStatus::Sent->value)->where('expires_at', '<=', now())
            ->orderBy('id')->pluck('id')->each(function (int $id): void {
                $envelope = DB::transaction(function () use ($id): ?Envelope {
                    $envelope = Envelope::query()->lockForUpdate()->find($id);
                    if ($envelope === null || $envelope->status !== EnvelopeStatus::Sent || $envelope->expires_at?->isFuture()) {
                        return null;
                    }
                    Signer::query()->where('envelope_id', $id)->orderBy('id')->lockForUpdate()->get();
                    EnvelopeLifecycleRecorded::record($envelope, AuditEventType::Expired, AuditActorType::System);
                    try {
                        DB::statement("SET LOCAL witness.envelope_transition = 'on'");
                        DB::table('envelopes')->where('id', $id)->update(['status' => EnvelopeStatus::Expired->value, 'updated_at' => now()]);
                    } finally {
                        DB::statement("SET LOCAL witness.envelope_transition = 'off'");
                    }

                    return $envelope->refresh()->load('sender');
                });
                if ($envelope !== null) {
                    try {
                        Mail::mailer((string) config('mail.default'))->to($envelope->sender->email)
                            ->send(new LifecycleNotification($envelope, 'expired'));
                    } catch (Throwable) {
                    }
                }
            });
    }

    private function remindDue(): void
    {
        Envelope::query()->where('status', EnvelopeStatus::Sent->value)->whereNotNull('reminder_interval_days')
            ->where('expires_at', '>', now())->with(['team', 'signers'])->orderBy('id')->get()
            ->each(function (Envelope $envelope): void {
                $anchor = $envelope->last_reminded_at ?? $envelope->sent_at;
                if ($anchor === null || $anchor->addDays((int) $envelope->reminder_interval_days)->isFuture()) {
                    return;
                }
                $delivered = false;
                foreach ($envelope->signers as $signer) {
                    $delivered = $this->rotateInvitation->handle(
                        $envelope->team,
                        null,
                        (int) $envelope->getKey(),
                        (int) $signer->getKey(),
                    ) || $delivered;
                }
                if ($delivered) {
                    Envelope::query()->whereKey($envelope)->update(['last_reminded_at' => now()]);
                }
            });
    }
}

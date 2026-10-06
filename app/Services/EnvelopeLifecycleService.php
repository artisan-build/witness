<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\RotateSigningInvitation;
use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Enums\SignerStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Mail\LifecycleNotification;
use App\Models\Envelope;
use App\Models\Signer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

final readonly class EnvelopeLifecycleService
{
    public function __construct(private RotateSigningInvitation $rotateInvitation) {}

    public function runDue(): void
    {
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
                    DB::statement("SET LOCAL witness.envelope_transition = 'on'");
                    DB::table('envelopes')->where('id', $id)->update(['status' => EnvelopeStatus::Expired->value, 'updated_at' => now()]);

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
            ->where('expires_at', '>', now())->with(['team', 'signers.mailDeliveries'])->orderBy('id')->get()
            ->each(function (Envelope $envelope): void {
                $anchor = $envelope->last_reminded_at ?? $envelope->sent_at;
                if ($anchor === null || $anchor->addDays((int) $envelope->reminder_interval_days)->isFuture()) {
                    return;
                }
                foreach ($envelope->signers as $signer) {
                    $latestInvitation = $signer->mailDeliveries
                        ->where('purpose', MailDeliveryPurpose::SigningInvitation)
                        ->sortByDesc('generation')->first();
                    if (! in_array($signer->status, [SignerStatus::Signed, SignerStatus::Declined], true)
                        && $latestInvitation?->status !== MailDeliveryStatus::Unknown) {
                        $this->rotateInvitation->handle($envelope->team, null, (int) $envelope->getKey(), (int) $signer->getKey());
                    }
                }
            });
    }
}

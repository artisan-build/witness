<?php

declare(strict_types=1);

use App\Domain\Audit\LifecyclePolicy;
use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Team;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;

function policyFixture(string $status = 'sent', int $auditEventCount = 3): array
{
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);

    /** @var Envelope $envelope */
    $envelope = Envelope::factory()
        ->for($team)
        ->for($sender, 'sender')
        ->create([
            'status' => EnvelopeStatus::Draft->value,
            'audit_event_count' => 0,
        ]);
    $signer = Signer::factory()->for($envelope)->create();

    DB::statement("SELECT set_config('witness.envelope_transition', 'on', true), set_config('witness.audit_append', 'on', true)");
    DB::table('envelopes')->where('id', $envelope->getKey())->update([
        'status' => $status,
        'audit_event_count' => $auditEventCount,
        'audit_chain_head' => $auditEventCount > 0 ? str_repeat('ab', 32) : null,
    ]);
    DB::statement("SELECT set_config('witness.envelope_transition', 'off', true), set_config('witness.audit_append', 'off', true)");

    return [$envelope->refresh(), $sender, $signer];
}

function assertRecordable(Envelope $envelope, AuditEventType $type, AuditActorType $actorType, ?string $actorId): void
{
    resolve(LifecyclePolicy::class)->assertRecordable($envelope, $type, $actorType, $actorId);
}

it('admits a signing event for an envelope signer on a sent envelope at the tail position', function (): void {
    [$envelope, $sender, $signer] = policyFixture();

    expect(fn () => assertRecordable($envelope, AuditEventType::Opened, AuditActorType::Signer, (string) $signer->getKey()))
        ->not->toThrow(DomainException::class);
});

it('requires created and sent events from the sender at their exact audit positions on a frozen envelope', function (): void {
    [$envelope, $sender, $signer] = policyFixture(status: 'sent', auditEventCount: 1);

    expect(fn () => assertRecordable($envelope, AuditEventType::Sent, AuditActorType::Sender, (string) $sender->getKey()))
        ->not->toThrow(DomainException::class)
        ->and(fn () => assertRecordable($envelope, AuditEventType::Sent, AuditActorType::Signer, (string) $signer->getKey()))
        ->toThrow(DomainException::class, 'requires the envelope sender');

    [$envelope] = policyFixture(status: 'sent', auditEventCount: 3);
    [$secondEnvelope, $secondSender] = policyFixture(status: 'sent', auditEventCount: 3);

    expect(fn () => assertRecordable($secondEnvelope, AuditEventType::Created, AuditActorType::Sender, (string) $secondSender->getKey()))
        ->toThrow(DomainException::class, 'not valid at this audit position')
        ->and(fn () => assertRecordable($secondEnvelope, AuditEventType::Sent, AuditActorType::Sender, (string) $secondSender->getKey()))
        ->toThrow(DomainException::class, 'not valid at this audit position');

    [$envelope] = policyFixture(status: 'draft', auditEventCount: 0);
    [$draftEnvelope, $draftSender] = policyFixture(status: 'draft', auditEventCount: 0);

    expect(fn () => assertRecordable($draftEnvelope, AuditEventType::Sent, AuditActorType::Sender, (string) $draftSender->getKey()))
        ->toThrow(DomainException::class, 'frozen envelope');
});

it('requires signing events to come from an envelope signer on an active sent envelope', function (): void {
    [$envelope, $sender, $signer] = policyFixture();
    $foreignSigner = Signer::factory()->for(Envelope::factory()->for(Team::factory()->create())->create())->create();

    expect(fn () => assertRecordable($envelope, AuditEventType::Opened, AuditActorType::System, null))
        ->toThrow(DomainException::class, 'requires an envelope signer')
        ->and(fn () => assertRecordable($envelope, AuditEventType::Opened, AuditActorType::Signer, (string) $foreignSigner->getKey()))
        ->toThrow(DomainException::class, 'must belong')
        ->and(fn () => assertRecordable($envelope->fresh(), AuditEventType::Opened, AuditActorType::Signer, (string) $signer->getKey()))
        ->not->toThrow(DomainException::class);

    [$draftEnvelope] = policyFixture(status: 'draft', auditEventCount: 0);
    $draftSigner = Signer::factory()->for($draftEnvelope)->create();

    expect(fn () => assertRecordable($draftEnvelope, AuditEventType::Opened, AuditActorType::Signer, (string) $draftSigner->getKey()))
        ->toThrow(DomainException::class, 'active sent envelope');
});

it('pins the terminal and reminder event rules to their exact actors and states', function (): void {
    [$envelope, $sender, $signer] = policyFixture(status: 'sent', auditEventCount: 3);

    expect(fn () => assertRecordable($envelope, AuditEventType::Voided, AuditActorType::System, null))
        ->toThrow(DomainException::class, 'not valid for this envelope and actor')
        ->and(fn () => assertRecordable($envelope, AuditEventType::Expired, AuditActorType::Sender, (string) $sender->getKey()))
        ->toThrow(DomainException::class, 'not valid for this envelope and actor')
        ->and(fn () => assertRecordable($envelope, AuditEventType::Reminded, AuditActorType::Signer, (string) $signer->getKey()))
        ->toThrow(DomainException::class, 'sender or the system actor')
        ->and(fn () => assertRecordable($envelope, AuditEventType::Completed, AuditActorType::Sender, (string) $sender->getKey()))
        ->toThrow(DomainException::class, 'requires the system actor')
        ->and(fn () => assertRecordable($envelope, AuditEventType::FinalDelivered, AuditActorType::System, null))
        ->toThrow(DomainException::class, 'requires a completed envelope and the system actor');

    [$completed] = policyFixture(status: 'completed', auditEventCount: 8);

    expect(fn () => assertRecordable($completed, AuditEventType::FinalDelivered, AuditActorType::System, null))
        ->not->toThrow(DomainException::class);
});

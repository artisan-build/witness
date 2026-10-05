<?php

declare(strict_types=1);

use App\Actions\CreateDraftEnvelope;
use App\Actions\SendEnvelope;
use App\Domain\Audit\AuditChainVerifier;
use App\Domain\Audit\AuditHasher;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Team;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Thunk\Verbs\Models\VerbEvent;

function createAuditedEnvelope(string $documentContents = 'original document'): array
{
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);

    $envelope = resolve(CreateDraftEnvelope::class)->handle(
        team: $team,
        sender: $sender,
        title: 'Test-created agreement',
        originalName: 'test-created.pdf',
        storagePath: 'documents/test-created.pdf',
        sizeBytes: strlen($documentContents),
        pageCount: 1,
        originalSha256: hash('sha256', $documentContents),
        ipAddress: '203.0.113.42',
        userAgent: 'Witness test agent',
    );

    return [$team, $sender, $envelope];
}

function tamperWithAuditEvents(Closure $callback): mixed
{
    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER witness_audit_events_guard');

    try {
        return $callback();
    } finally {
        DB::statement('ALTER TABLE audit_events ENABLE TRIGGER witness_audit_events_guard');
    }
}

it('builds and verifies a real two-event envelope chain through Verbs', function (): void {
    [$team, $sender, $envelope] = createAuditedEnvelope();

    resolve(SendEnvelope::class)->handle(
        team: $team,
        sender: $sender,
        envelopeId: $envelope->getKey(),
        ipAddress: '198.51.100.17',
        userAgent: 'Witness send test agent',
    );

    $events = $envelope->auditEvents()->get();
    $result = resolve(AuditChainVerifier::class)->verify($envelope);

    expect($events)->toHaveCount(2)
        ->and($events->pluck('type')->all())->toBe([AuditEventType::Created, AuditEventType::Sent])
        ->and($events[0]->ip_address)->toBe('203.0.113.42')
        ->and($events[1]->user_agent)->toBe('Witness send test agent')
        ->and(VerbEvent::query()->count())->toBe(2)
        ->and($result->valid)->toBeTrue()
        ->and($result->chainHead)->toBe($events[1]->hash);
});

it('fails at canonical event data that was directly tampered with', function (): void {
    [$team, $sender, $envelope] = createAuditedEnvelope();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());
    $second = $envelope->auditEvents()->where('sequence', 2)->firstOrFail();

    tamperWithAuditEvents(
        fn () => DB::table('audit_events')->where('id', $second->getKey())->update(['canonical_event' => '{}']),
    );

    $result = resolve(AuditChainVerifier::class)->verify($envelope);

    expect($result->valid)->toBeFalse()
        ->and($result->failingSequence)->toBe(2)
        ->and($result->failingEventId)->toBe($second->getKey());
});

it('fails at the first event moved out of sequence', function (): void {
    [$team, $sender, $envelope] = createAuditedEnvelope();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());
    $second = $envelope->auditEvents()->where('sequence', 2)->firstOrFail();

    tamperWithAuditEvents(
        fn () => DB::table('audit_events')->where('id', $second->getKey())->update(['sequence' => 3]),
    );

    $result = resolve(AuditChainVerifier::class)->verify($envelope);

    expect($result->valid)->toBeFalse()
        ->and($result->failingSequence)->toBe(2)
        ->and($result->failingEventId)->toBe($second->getKey());
});

it('detects predecessor and event hash changes', function (string $column): void {
    [$team, $sender, $envelope] = createAuditedEnvelope();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());
    $first = $envelope->auditEvents()->firstOrFail();

    tamperWithAuditEvents(
        fn () => DB::table('audit_events')->where('id', $first->getKey())->update([
            $column => str_repeat('0', 64),
        ]),
    );

    $result = resolve(AuditChainVerifier::class)->verify($envelope);

    expect($result->valid)->toBeFalse()
        ->and($result->failingSequence)->toBe(1)
        ->and($result->failingEventId)->toBe($first->getKey());
})->with(['prev_hash', 'hash']);

it('derives genesis from both envelope identity and original document hash', function (): void {
    $hasher = resolve(AuditHasher::class);
    $hashA = hash('sha256', 'document A');
    $hashB = hash('sha256', 'document B');

    expect($hasher->genesis(10, $hashA))->not->toBe($hasher->genesis(11, $hashA))
        ->and($hasher->genesis(10, $hashA))->not->toBe($hasher->genesis(10, $hashB));
});

it('refuses audit event updates and deletes through the application model', function (): void {
    [$team, $sender, $envelope] = createAuditedEnvelope();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());
    $event = $envelope->auditEvents()->firstOrFail();

    expect(fn () => $event->update(['user_agent' => 'changed']))
        ->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $event->delete())
        ->toThrow(LogicException::class, 'append-only')
        ->and(fn () => AuditEvent::query()->whereKey($event)->update(['user_agent' => 'changed']))
        ->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $envelope->auditEvents()->delete())
        ->toThrow(LogicException::class, 'append-only')
        ->and(AuditEvent::query()->count())->toBe(2);
});

it('defines every PRD audit event type', function (): void {
    expect(array_map(
        static fn (AuditEventType $type): string => $type->value,
        AuditEventType::cases(),
    ))->toBe([
        'created',
        'sent',
        'email_sent',
        'opened',
        'code_sent',
        'code_verified',
        'code_failed',
        'consented',
        'field_completed',
        'signed',
        'declined',
        'reminded',
        'voided',
        'expired',
        'completed',
        'final_delivered',
    ]);
});

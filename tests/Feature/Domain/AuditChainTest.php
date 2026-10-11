<?php

declare(strict_types=1);

use App\Actions\CreateDraftEnvelope;
use App\Actions\SendEnvelope;
use App\Domain\Audit\AuditCanonicalizer;
use App\Domain\Audit\AuditChainVerifier;
use App\Domain\Audit\AuditHasher;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Support\CanonicalJson;
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
    $signer = $envelope->signers()->create([
        'name' => 'Audit signer',
        'email' => 'audit-'.$envelope->getKey().'@example.test',
    ]);
    $signer->fields()->create([
        'page' => 1,
        'type' => 'signature',
        'required' => true,
        'position_x' => .1,
        'position_y' => .1,
        'width' => .2,
        'height' => .1,
    ]);

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

it('builds and verifies the send and invitation envelope chain through Verbs', function (): void {
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

    expect($events)->toHaveCount(3)
        ->and($events->pluck('type')->all())->toBe([AuditEventType::Created, AuditEventType::Sent, AuditEventType::EmailSent])
        ->and($events[0]->ip_address)->toBe('203.0.113.42')
        ->and($events[1]->user_agent)->toBe('Witness send test agent')
        ->and(VerbEvent::query()->count())->toBe(3)
        ->and($result->valid)->toBeTrue()
        ->and($result->chainHead)->toBe($events[2]->hash);
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
    $third = $envelope->auditEvents()->where('sequence', 3)->firstOrFail();

    tamperWithAuditEvents(
        fn () => DB::table('audit_events')->where('id', $second->getKey())->update(['sequence' => 4]),
    );

    $result = resolve(AuditChainVerifier::class)->verify($envelope);

    expect($result->valid)->toBeFalse()
        ->and($result->failingSequence)->toBe(2)
        ->and($result->failingEventId)->toBe($third->getKey());
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

it('rejects a fully consistent forged chain that does not begin with created', function (): void {
    [$team, $sender, $envelope] = createAuditedEnvelope();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    $canonicalizer = resolve(AuditCanonicalizer::class);
    $hasher = resolve(AuditHasher::class);
    $document = $envelope->document()->firstOrFail();
    $genesis = $hasher->genesis((int) $envelope->getKey(), $document->original_sha256);

    tamperWithAuditEvents(function () use ($envelope, $canonicalizer, $hasher, $genesis): void {
        $event = $envelope->auditEvents()->orderBy('sequence')->firstOrFail();

        $canonical = $canonicalizer->encode(
            envelopeId: (int) $envelope->getKey(),
            verbEventId: $event->verb_event_id,
            sequence: 1,
            type: AuditEventType::Sent,
            occurredAt: $event->occurred_at,
            actorType: $event->actor_type,
            actorId: $event->actor_id,
            ipAddress: $event->ip_address,
            userAgent: $event->user_agent,
            payload: $event->payload,
        );
        $head = $hasher->eventHash($genesis, $canonical);

        DB::table('audit_events')->where('id', $event->getKey())->update([
            'type' => 'sent',
            'canonical_event' => $canonical,
            'prev_hash' => $genesis,
            'hash' => $head,
        ]);
        DB::table('audit_events')->where('envelope_id', $envelope->getKey())->where('sequence', '>', 1)->delete();

        DB::statement("SELECT set_config('witness.audit_append', 'on', true)");
        DB::table('envelopes')->where('id', $envelope->getKey())->update([
            'audit_event_count' => 1,
            'audit_chain_head' => $head,
        ]);
    });

    $result = resolve(AuditChainVerifier::class)->verify($envelope);

    expect($result->valid)->toBeFalse()
        ->and($result->failingSequence)->toBe(1)
        ->and($result->reason)->toContain('must begin with created');
});

it('rejects a chain whose stored envelope anchor head diverges from the recomputed tail', function (): void {
    [$team, $sender, $envelope] = createAuditedEnvelope();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    DB::statement("SELECT set_config('witness.audit_append', 'on', true)");
    DB::table('envelopes')->where('id', $envelope->getKey())->update([
        'audit_chain_head' => str_repeat('0', 64),
    ]);

    $result = resolve(AuditChainVerifier::class)->verify($envelope);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toContain('audit head');
});

it('detects a consistently forged extra audit row against the envelope anchor', function (): void {
    [$team, $sender, $envelope] = createAuditedEnvelope();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    $canonicalizer = resolve(AuditCanonicalizer::class);
    $hasher = resolve(AuditHasher::class);

    tamperWithAuditEvents(function () use ($envelope, $canonicalizer, $hasher): void {
        $last = AuditEvent::query()
            ->where('envelope_id', $envelope->getKey())
            ->orderByDesc('sequence')
            ->firstOrFail();
        $when = $last->occurred_at->addSeconds(5);

        $canonical = $canonicalizer->encode(
            envelopeId: (int) $envelope->getKey(),
            verbEventId: 987654321,
            sequence: $last->sequence + 1,
            type: AuditEventType::Opened,
            occurredAt: $when,
            actorType: $last->actor_type,
            actorId: $last->actor_id,
            ipAddress: $last->ip_address,
            userAgent: $last->user_agent,
            payload: $last->payload,
        );

        DB::table('audit_events')->insert([
            'envelope_id' => $envelope->getKey(),
            'verb_event_id' => 987654321,
            'sequence' => $last->sequence + 1,
            'type' => 'opened',
            'occurred_at' => $when->format('Y-m-d H:i:s.u'),
            'actor_type' => $last->actor_type->value,
            'actor_id' => $last->actor_id,
            'ip_address' => $last->ip_address,
            'user_agent' => $last->user_agent,
            'payload' => resolve(CanonicalJson::class)->encode($last->payload),
            'canonical_event' => $canonical,
            'prev_hash' => $last->hash,
            'hash' => $hasher->eventHash($last->hash, $canonical),
            'created_at' => $last->created_at,
        ]);
    });

    $forged = AuditEvent::query()->where('verb_event_id', 987654321)->firstOrFail();
    $recomputed = $canonicalizer->encode(
        envelopeId: (int) $envelope->getKey(),
        verbEventId: $forged->verb_event_id,
        sequence: $forged->sequence,
        type: $forged->type,
        occurredAt: $forged->occurred_at,
        actorType: $forged->actor_type,
        actorId: $forged->actor_id,
        ipAddress: $forged->ip_address,
        userAgent: $forged->user_agent,
        payload: $forged->payload,
    );
    expect($recomputed)->toBe($forged->canonical_event);

    $result = resolve(AuditChainVerifier::class)->verify($envelope);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toContain('Expected 3 audit events, found 4');
});

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
        ->and(AuditEvent::query()->count())->toBe(3);
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

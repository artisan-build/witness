<?php

declare(strict_types=1);

use App\Actions\CreateDraftEnvelope;
use App\Actions\SendEnvelope;
use App\Actions\StoreFinalDocument;
use App\Actions\UpdateFieldResponse;
use App\Domain\Audit\AuditChainVerifier;
use App\Domain\Audit\LifecyclePolicy;
use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Field;
use App\Models\Signer;
use App\Models\Team;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\TemplateRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Thunk\Verbs\Models\VerbEvent;

function createSecurityEnvelope(string $contents = 'security document'): array
{
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);
    $envelope = resolve(CreateDraftEnvelope::class)->handle(
        team: $team,
        sender: $sender,
        title: 'Security regression',
        originalName: 'security.pdf',
        storagePath: 'documents/security.pdf',
        sizeBytes: strlen($contents),
        pageCount: 1,
        originalSha256: hash('sha256', $contents),
        ipAddress: '192.0.2.10',
        userAgent: 'Witness security test',
    );
    $signer = $envelope->signers()->create([
        'name' => 'Security signer',
        'email' => 'security-'.$envelope->getKey().'@example.test',
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

it('roots genesis in the document hash frozen at send', function (): void {
    [$team, $sender, $envelope] = createSecurityEnvelope('first document');
    $replacementHash = hash('sha256', 'replacement document');

    $envelope->document->update([
        'storage_path' => 'documents/replacement.pdf',
        'original_sha256' => $replacementHash,
    ]);
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    $events = $envelope->auditEvents()->get();
    $result = resolve(AuditChainVerifier::class)->verify($envelope);

    expect($events)->toHaveCount(3)
        ->and($events->first()->occurred_at->equalTo($envelope->created_at))->toBeTrue()
        ->and($result->valid)->toBeTrue();
});

it('allows only locked domain actions to write signer results and refuses premature final artifacts', function (): void {
    [$team, $sender, $envelope] = createSecurityEnvelope();
    $signer = Signer::factory()->for($envelope)->create();
    $field = Field::factory()->forSigner($signer)->create();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    $field = resolve(UpdateFieldResponse::class)->handle(
        $envelope,
        $signer,
        $field,
        ['text' => 'Approved'],
        now(),
    );
    Storage::fake('local');
    Storage::put('documents/final.pdf', 'final document');

    expect($field->value)->toBe(['text' => 'Approved'])
        ->and($field->completed_at)->not->toBeNull()
        ->and(fn () => resolve(StoreFinalDocument::class)->handle(
            $envelope,
            'documents/final.pdf',
            hash('sha256', 'final document'),
        ))->toThrow(DomainException::class, 'fully signed');
});

it('rejects direct result and final artifact writes', function (): void {
    [$team, $sender, $envelope] = createSecurityEnvelope();
    $signer = Signer::factory()->for($envelope)->create();
    $field = Field::factory()->forSigner($signer)->create();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    expect(fn () => DB::transaction(
        fn () => Field::query()->whereKey($field)->update(['value' => json_encode(['text' => 'forged'])]),
    ))->toThrow(QueryException::class, 'locked domain action')
        ->and(fn () => DB::transaction(
            fn () => $envelope->document()->update(['final_storage_path' => 'documents/forged.pdf']),
        ))->toThrow(QueryException::class, 'locked domain action');
});

it('rejects builder relation and parent cascade changes to a frozen envelope', function (): void {
    [$team, $sender, $envelope] = createSecurityEnvelope();
    $signer = Signer::factory()->for($envelope)->create();
    $field = Field::factory()->forSigner($signer)->create();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    expect(fn () => DB::transaction(
        fn () => Field::query()->whereKey($field)->update(['position_x' => '0.5000000000']),
    ))->toThrow(QueryException::class, 'frozen after send')
        ->and(fn () => DB::transaction(
            fn () => $envelope->document()->update(['original_name' => 'forged.pdf']),
        ))->toThrow(QueryException::class, 'frozen after send')
        ->and(fn () => DB::transaction(fn () => Signer::query()->whereKey($signer)->delete()))
        ->toThrow(QueryException::class, 'cannot be removed after send')
        ->and(fn () => DB::transaction(fn () => Envelope::query()->whereKey($envelope)->delete()))
        ->toThrow(QueryException::class, 'Only draft envelopes');

    expect($field->fresh())->not->toBeNull()
        ->and($signer->fresh())->not->toBeNull();
});

it('rejects ownership and container moves through instance and builder paths', function (): void {
    [$teamA, $senderA, $envelopeA] = createSecurityEnvelope('team A');
    [$teamB, $senderB, $envelopeB] = createSecurityEnvelope('team B');
    $signer = Signer::factory()->for($envelopeA)->create();
    $field = Field::factory()->forSigner($signer)->create();
    $templateA = Template::factory()->forOwner($teamA, $senderA)->create();
    $templateB = Template::factory()->forOwner($teamB, $senderB)->create();
    $role = TemplateRole::factory()->for($templateA)->create();
    $templateField = TemplateField::factory()->forRole($role)->create();

    expect(fn () => DB::transaction(fn () => Envelope::query()->whereKey($envelopeA)->update([
        'team_id' => $teamB->getKey(),
        'sender_id' => $senderB->getKey(),
    ])))->toThrow(QueryException::class, 'immutable')
        ->and(fn () => DB::transaction(
            fn () => Signer::query()->whereKey($signer)->update(['envelope_id' => $envelopeB->getKey()]),
        ))->toThrow(QueryException::class, 'immutable')
        ->and(fn () => DB::transaction(
            fn () => TemplateRole::query()->whereKey($role)->update(['template_id' => $templateB->getKey()]),
        ))->toThrow(QueryException::class, 'immutable')
        ->and(fn () => DB::transaction(
            fn () => Template::query()->whereKey($templateA)->update([
                'team_id' => $teamB->getKey(),
                'sender_id' => $senderB->getKey(),
            ]),
        ))->toThrow(QueryException::class, 'immutable');

    expect($field->fresh()->envelope_id)->toBe($envelopeA->getKey())
        ->and($templateField->fresh()->template_id)->toBe($templateA->getKey());
});

it('rejects cross-team ownership at model creation', function (): void {
    $team = Team::factory()->create();
    $outsider = UserFactory::new()->create();
    $envelope = new Envelope(['title' => 'Forged ownership']);
    $envelope->team_id = $team->getKey();
    $envelope->sender_id = $outsider->getKey();
    $envelope->status = EnvelopeStatus::Draft;
    $template = new Template([
        'name' => 'Forged template',
        'title' => 'Forged template',
        'original_name' => 'forged.pdf',
        'storage_path' => 'templates/forged.pdf',
        'size_bytes' => 100,
        'page_count' => 1,
        'original_sha256' => hash('sha256', 'forged'),
    ]);
    $template->team_id = $team->getKey();
    $template->sender_id = $outsider->getKey();

    expect(fn () => $envelope->save())
        ->toThrow(DomainException::class, 'must belong')
        ->and(fn () => $template->save())
        ->toThrow(DomainException::class, 'must belong');
});

it('enforces actor identity and rejects reminded events outside the active lifecycle', function (): void {
    [$team, $sender, $envelope] = createSecurityEnvelope();
    $otherEnvelope = Envelope::factory()->create();
    $otherSigner = Signer::factory()->for($otherEnvelope)->create();
    $policy = resolve(LifecyclePolicy::class);

    expect(fn () => $policy->assertActor($envelope, AuditActorType::Sender, (string) $otherEnvelope->sender_id))
        ->toThrow(DomainException::class, 'must own')
        ->and(fn () => $policy->assertActor($envelope, AuditActorType::Signer, (string) $otherSigner->getKey()))
        ->toThrow(DomainException::class, 'must belong')
        ->and(fn () => $policy->assertActor($envelope, AuditActorType::System, 'forged-system-id'))
        ->toThrow(DomainException::class, 'cannot have')
        ->and(fn () => EnvelopeLifecycleRecorded::record(
            $envelope,
            AuditEventType::Reminded,
            AuditActorType::Sender,
            $sender->getKey(),
        ))->toThrow(DomainException::class, 'active sent envelope');

    expect(VerbEvent::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('keeps Verbs and audit projection atomic when projection fails', function (): void {
    [, $sender, $envelope] = createSecurityEnvelope();

    DB::transaction(function () use ($envelope): void {
        DB::statement("SET LOCAL witness.envelope_transition = 'on'");
        DB::table('envelopes')->where('id', $envelope->getKey())->update(['status' => EnvelopeStatus::Sent]);
    });
    $envelope->refresh();

    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION witness_test_reject_audit_insert() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'Forced audit projection failure';
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER witness_test_reject_audit_insert
        BEFORE INSERT ON audit_events
        FOR EACH ROW EXECUTE FUNCTION witness_test_reject_audit_insert();
        SQL);

    try {
        expect(fn () => EnvelopeLifecycleRecorded::record(
            $envelope,
            AuditEventType::Created,
            AuditActorType::Sender,
            $sender->getKey(),
        ))->toThrow(QueryException::class, 'Forced audit projection failure');
    } finally {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS witness_test_reject_audit_insert ON audit_events;
            DROP FUNCTION IF EXISTS witness_test_reject_audit_insert();
            SQL);
    }

    expect(VerbEvent::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0)
        ->and($envelope->fresh()->audit_event_count)->toBe(0);
});

it('reloads the first uncommitted event before a normal send records the second', function (): void {
    [$team, $sender, $envelope] = createSecurityEnvelope();
    $stateReloads = 0;

    DB::listen(function (QueryExecuted $query) use (&$stateReloads): void {
        if (str_contains($query->sql, 'verb_state_events')) {
            $stateReloads++;
        }
    });

    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    expect($stateReloads)->toBeGreaterThanOrEqual(2)
        ->and(VerbEvent::query()->count())->toBe(3)
        ->and(AuditEvent::query()->count())->toBe(3);
});

it('retries a send in the same process after its outer transaction rolls back', function (): void {
    [$team, $sender, $envelope] = createSecurityEnvelope();

    DB::beginTransaction();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    expect(VerbEvent::query()->count())->toBe(3)
        ->and(AuditEvent::query()->count())->toBe(3);

    DB::rollBack();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Draft)
        ->and(VerbEvent::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);

    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Sent)
        ->and(VerbEvent::query()->count())->toBe(3)
        ->and(AuditEvent::query()->count())->toBe(3);
});

it('rejects forwarded audit update and unscoped delete paths in PostgreSQL', function (): void {
    [$team, $sender, $envelope] = createSecurityEnvelope();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());
    $event = $envelope->auditEvents()->firstOrFail();

    expect(fn () => DB::transaction(fn () => AuditEvent::query()->updateOrInsert(
        ['id' => $event->getKey()],
        ['user_agent' => 'mutated'],
    )))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(
            fn () => AuditEvent::query()->whereKey($event)->toBase()->delete(),
        ))->toThrow(QueryException::class, 'envelope purge')
        ->and(fn () => DB::transaction(function () use ($envelope, $event): void {
            DB::statement("SELECT set_config('witness.purge_envelope', ?, true)", [
                (string) ((int) $envelope->getKey() + 1),
            ]);
            AuditEvent::query()->whereKey($event)->toBase()->delete();
        }))->toThrow(QueryException::class, 'envelope purge');

    expect(AuditEvent::query()->count())->toBe(3);
});

it('detects empty and tail-truncated chains against envelope anchors', function (): void {
    [$team, $sender, $envelope] = createSecurityEnvelope();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());
    DB::statement("SELECT set_config('witness.purge_envelope', ?, true)", [(string) $envelope->getKey()]);
    DB::table('audit_events')->where('envelope_id', $envelope->getKey())->where('sequence', 3)->delete();

    $tailResult = resolve(AuditChainVerifier::class)->verify($envelope);
    DB::table('audit_events')->where('envelope_id', $envelope->getKey())->delete();
    $emptyResult = resolve(AuditChainVerifier::class)->verify($envelope);

    expect($tailResult->valid)->toBeFalse()
        ->and($tailResult->reason)->toContain('Expected 3 audit events')
        ->and($emptyResult->valid)->toBeFalse()
        ->and($emptyResult->reason)->toContain('no audit chain');
});

it('keeps inherited lifecycle commit paths out of application use', function (): void {
    expect(fn () => EnvelopeLifecycleRecorded::make())
        ->toThrow(LogicException::class, 'record()')
        ->and(fn () => EnvelopeLifecycleRecorded::commit())
        ->toThrow(LogicException::class, 'record()')
        ->and(fn () => EnvelopeLifecycleRecorded::fire())
        ->toThrow(LogicException::class, 'record()');
});

it('serializes a field definition edit against send on PostgreSQL', function (): void {
    /** @var array{host: string, port: int|string, database: string, username: string, password: string} $config */
    $config = config('database.connections.pgsql');
    $connect = static fn (): PDO => new PDO(
        "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $setup = $connect();
    $send = $connect();
    $edit = $connect();
    $suffix = bin2hex(random_bytes(6));
    $userId = $teamId = $envelopeId = null;

    try {
        $setup->beginTransaction();
        $statement = $setup->prepare(
            'INSERT INTO users (name, email, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) RETURNING id',
        );
        $statement->execute(['Concurrent sender', "concurrent-{$suffix}@example.test"]);
        $userId = (int) $statement->fetchColumn();
        $statement = $setup->prepare(
            'INSERT INTO teams (name, created_at, updated_at) VALUES (?, NOW(), NOW()) RETURNING id',
        );
        $statement->execute(["Concurrent team {$suffix}"]);
        $teamId = (int) $statement->fetchColumn();
        $setup->prepare(
            'INSERT INTO team_user (team_id, user_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
        )->execute([$teamId, $userId]);
        $statement = $setup->prepare(
            "INSERT INTO envelopes (team_id, sender_id, title, status, created_at, updated_at)
             VALUES (?, ?, ?, 'draft', NOW(), NOW()) RETURNING id",
        );
        $statement->execute([$teamId, $userId, "Concurrent envelope {$suffix}"]);
        $envelopeId = (int) $statement->fetchColumn();
        $statement = $setup->prepare(
            "INSERT INTO signers (envelope_id, name, email, status, created_at, updated_at)
             VALUES (?, ?, ?, 'pending', NOW(), NOW()) RETURNING id",
        );
        $statement->execute([$envelopeId, 'Concurrent signer', "signer-{$suffix}@example.test"]);
        $signerId = (int) $statement->fetchColumn();
        $statement = $setup->prepare(
            "INSERT INTO fields (
                envelope_id, signer_id, page, type, required, position_x, position_y, width, height,
                created_at, updated_at
             ) VALUES (?, ?, 1, 'signature', true, 0.1, 0.2, 0.3, 0.1, NOW(), NOW()) RETURNING id",
        );
        $statement->execute([$envelopeId, $signerId]);
        $fieldId = (int) $statement->fetchColumn();
        $setup->commit();

        $send->beginTransaction();
        $send->prepare('SELECT id FROM envelopes WHERE id = ? FOR UPDATE')->execute([$envelopeId]);
        $send->exec("SET LOCAL witness.envelope_transition = 'on'");
        $send->prepare("UPDATE envelopes SET status = 'sent', sent_at = NOW() WHERE id = ?")
            ->execute([$envelopeId]);

        $edit->beginTransaction();
        $edit->exec("SET LOCAL lock_timeout = '100ms'");
        $blockedCode = null;

        try {
            $edit->prepare('UPDATE fields SET position_x = 0.5 WHERE id = ?')->execute([$fieldId]);
        } catch (PDOException $exception) {
            $blockedCode = $exception->getCode();
        }
        $edit->rollBack();
        $send->commit();

        $edit->beginTransaction();
        $frozenCode = null;

        try {
            $edit->prepare('UPDATE fields SET position_x = 0.5 WHERE id = ?')->execute([$fieldId]);
        } catch (PDOException $exception) {
            $frozenCode = $exception->getCode();
        }
        $edit->rollBack();

        expect($blockedCode)->toBe('55P03')
            ->and($frozenCode)->toBe('P0001');
    } finally {
        if ($send->inTransaction()) {
            $send->rollBack();
        }

        if ($edit->inTransaction()) {
            $edit->rollBack();
        }

        if ($envelopeId !== null) {
            $setup->beginTransaction();
            $setup->exec("SET LOCAL witness.envelope_transition = 'on'");
            $setup->prepare("UPDATE envelopes SET status = 'draft' WHERE id = ?")->execute([$envelopeId]);
            $setup->prepare('DELETE FROM envelopes WHERE id = ?')->execute([$envelopeId]);
            $setup->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);
            $setup->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            $setup->commit();
        }
    }
});

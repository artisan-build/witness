<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\Support\PdfFixture;

function senderRaceConnection(): PDO
{
    /** @var array{host: string, port: int|string, database: string, username: string, password: string} $config */
    $config = config('database.connections.pgsql');

    return new PDO(
        "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/** @param array<array-key, mixed> $payload */
function startSenderOperation(string $operation, int $teamId, int $userId, int $recordId, array $payload = []): Process
{
    $process = new Process([
        PHP_BINARY,
        base_path('tests/Support/concurrent-operation.php'),
        $operation,
        (string) $teamId,
        (string) $userId,
        (string) $recordId,
        json_encode($payload, JSON_THROW_ON_ERROR),
    ], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => config('database.connections.pgsql.database'),
        'DB_URL' => '',
    ]);
    $process->setTimeout(10);
    $process->start();

    return $process;
}

/** @return array<string, mixed> */
function finishSenderOperation(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('serializes send, page reduction, and role edits against stale concurrent requests', function (): void {
    $setup = senderRaceConnection();
    $locker = senderRaceConnection();
    $suffix = bin2hex(random_bytes(6));
    $userId = $teamId = null;

    try {
        $statement = $setup->prepare('INSERT INTO users (name, email, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) RETURNING id');
        $statement->execute(['Race sender', "race-{$suffix}@example.test"]);
        $userId = (int) $statement->fetchColumn();
        $statement = $setup->prepare('INSERT INTO teams (name, created_at, updated_at) VALUES (?, NOW(), NOW()) RETURNING id');
        $statement->execute(["Race team {$suffix}"]);
        $teamId = (int) $statement->fetchColumn();
        $setup->prepare('INSERT INTO team_user (team_id, user_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')->execute([$teamId, $userId]);

        $createEnvelope = function (string $title) use ($setup, $teamId, $userId, $suffix): array {
            $statement = $setup->prepare("INSERT INTO envelopes (team_id, sender_id, title, status, created_at, updated_at) VALUES (?, ?, ?, 'draft', NOW(), NOW()) RETURNING id");
            $statement->execute([$teamId, $userId, $title]);
            $envelopeId = (int) $statement->fetchColumn();
            $setup->prepare("INSERT INTO documents (envelope_id, original_name, storage_path, size_bytes, page_count, original_sha256, created_at, updated_at) VALUES (?, 'race.pdf', ?, 100, 2, ?, NOW(), NOW())")
                ->execute([$envelopeId, "documents/race-{$envelopeId}.pdf", hash('sha256', "race-{$suffix}-{$envelopeId}")]);
            $statement = $setup->prepare("INSERT INTO signers (envelope_id, name, email, status, position, created_at, updated_at) VALUES (?, 'Race signer', ?, 'pending', 0, NOW(), NOW()) RETURNING id");
            $statement->execute([$envelopeId, "signer-{$envelopeId}-{$suffix}@example.test"]);
            $signerId = (int) $statement->fetchColumn();
            $statement = $setup->prepare("INSERT INTO fields (envelope_id, signer_id, page, type, required, position_x, position_y, width, height, created_at, updated_at) VALUES (?, ?, 1, 'signature', true, 0.1, 0.1, 0.2, 0.1, NOW(), NOW()) RETURNING id");
            $statement->execute([$envelopeId, $signerId]);

            return [$envelopeId, $signerId, (int) $statement->fetchColumn()];
        };
        [$sendEnvelope, $sendSigner] = $createEnvelope('Send race');
        [$pageEnvelope, $pageSigner] = $createEnvelope('Page race');
        [$metadataEnvelope] = $createEnvelope('Metadata race');

        $createTemplate = function (string $name) use ($setup, $teamId, $userId, $suffix): array {
            $statement = $setup->prepare("INSERT INTO templates (team_id, sender_id, name, title, original_name, storage_path, size_bytes, page_count, original_sha256, created_at, updated_at) VALUES (?, ?, ?, ?, 'race.pdf', ?, 100, 2, ?, NOW(), NOW()) RETURNING id");
            $statement->execute([$teamId, $userId, $name, $name, "documents/template-{$name}.pdf", hash('sha256', "template-{$suffix}-{$name}")]);
            $templateId = (int) $statement->fetchColumn();
            $statement = $setup->prepare("INSERT INTO template_roles (template_id, label, position, created_at, updated_at) VALUES (?, 'Signer', 0, NOW(), NOW()) RETURNING id");
            $statement->execute([$templateId]);

            return [$templateId, (int) $statement->fetchColumn()];
        };
        [$pageTemplate, $pageRole] = $createTemplate('Template page race');
        [$roleTemplate, $roleId] = $createTemplate('Template role race');

        $locker->beginTransaction();
        $locker->prepare('SELECT id FROM envelopes WHERE id = ? FOR UPDATE')->execute([$sendEnvelope]);
        $locker->prepare('DELETE FROM fields WHERE envelope_id = ?')->execute([$sendEnvelope]);
        $locker->prepare('DELETE FROM signers WHERE id = ?')->execute([$sendSigner]);
        $process = startSenderOperation('send', $teamId, $userId, $sendEnvelope);
        Sleep::usleep(250000);
        expect($process->isRunning())->toBeTrue();
        $locker->commit();
        $result = finishSenderOperation($process);
        expect($result['status'])->toBe('refused')
            ->and($result['message'])->toContain('at least one signer');

        $locker->beginTransaction();
        $locker->prepare('SELECT id FROM envelopes WHERE id = ? FOR UPDATE')->execute([$metadataEnvelope]);
        $locker->exec("SET LOCAL witness.envelope_transition = 'on'");
        $locker->prepare("UPDATE envelopes SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$metadataEnvelope]);
        $process = startSenderOperation('envelope-update', $teamId, $userId, $metadataEnvelope, [
            'title' => 'Stale metadata write',
            'message' => null,
            'expires_at' => null,
        ]);
        Sleep::usleep(250000);
        expect($process->isRunning())->toBeTrue();
        $locker->commit();
        $result = finishSenderOperation($process);
        expect($result['status'])->toBe('refused')
            ->and($result['status_code'])->toBe(409);

        $envelopeField = [[
            'assignee_id' => $pageSigner,
            'page' => 2,
            'type' => 'signature',
            'required' => true,
            'position_x' => .1,
            'position_y' => .1,
            'width' => .2,
            'height' => .1,
        ]];
        $locker->beginTransaction();
        $locker->prepare('SELECT id FROM envelopes WHERE id = ? FOR UPDATE')->execute([$pageEnvelope]);
        $locker->prepare('UPDATE documents SET page_count = 1 WHERE envelope_id = ?')->execute([$pageEnvelope]);
        $locker->prepare('DELETE FROM fields WHERE envelope_id = ?')->execute([$pageEnvelope]);
        $process = startSenderOperation('envelope-fields', $teamId, $userId, $pageEnvelope, $envelopeField);
        Sleep::usleep(250000);
        expect($process->isRunning())->toBeTrue();
        $locker->commit();
        $result = finishSenderOperation($process);
        expect($result['status'])->toBe('refused')
            ->and($result['message'])->toContain('signer and page');

        $templateField = static fn (int $role): array => [[
            'assignee_id' => $role,
            'page' => 2,
            'type' => 'signature',
            'required' => true,
            'position_x' => .1,
            'position_y' => .1,
            'width' => .2,
            'height' => .1,
        ]];
        $locker->beginTransaction();
        $locker->prepare('SELECT id FROM templates WHERE id = ? FOR UPDATE')->execute([$pageTemplate]);
        $locker->prepare('UPDATE templates SET page_count = 1 WHERE id = ?')->execute([$pageTemplate]);
        $process = startSenderOperation('template-fields', $teamId, $userId, $pageTemplate, $templateField($pageRole));
        Sleep::usleep(250000);
        expect($process->isRunning())->toBeTrue();
        $locker->commit();
        $result = finishSenderOperation($process);
        expect($result['status'])->toBe('refused')
            ->and($result['message'])->toContain('role and stay within a page');

        $locker->beginTransaction();
        $locker->prepare('SELECT id FROM templates WHERE id = ? FOR UPDATE')->execute([$roleTemplate]);
        $locker->prepare('DELETE FROM template_roles WHERE id = ?')->execute([$roleId]);
        $process = startSenderOperation('template-fields', $teamId, $userId, $roleTemplate, $templateField($roleId));
        Sleep::usleep(250000);
        expect($process->isRunning())->toBeTrue();
        $locker->commit();
        $result = finishSenderOperation($process);
        expect($result['status'])->toBe('refused')
            ->and($result['message'])->toContain('role and stay within a page');
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }

        if ($teamId !== null) {
            $setup->beginTransaction();
            $setup->exec("SET LOCAL witness.envelope_transition = 'on'");
            $setup->prepare("UPDATE envelopes SET status = 'draft' WHERE team_id = ? AND status <> 'draft'")->execute([$teamId]);
            $setup->commit();
            $setup->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);
        }
        if ($userId !== null) {
            $setup->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }
});

it('keeps only the committed object after overlapping envelope and template replacements', function (): void {
    $setup = senderRaceConnection();
    $locker = senderRaceConnection();
    $suffix = bin2hex(random_bytes(6));
    $userId = $teamId = null;
    $sources = [];

    try {
        $statement = $setup->prepare('INSERT INTO users (name, email, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) RETURNING id');
        $statement->execute(['Replacement sender', "replacement-{$suffix}@example.test"]);
        $userId = (int) $statement->fetchColumn();
        $statement = $setup->prepare('INSERT INTO teams (name, created_at, updated_at) VALUES (?, NOW(), NOW()) RETURNING id');
        $statement->execute(["Replacement team {$suffix}"]);
        $teamId = (int) $statement->fetchColumn();
        $setup->prepare('INSERT INTO team_user (team_id, user_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')->execute([$teamId, $userId]);

        $statement = $setup->prepare("INSERT INTO envelopes (team_id, sender_id, title, status, created_at, updated_at) VALUES (?, ?, 'Replacement envelope', 'draft', NOW(), NOW()) RETURNING id");
        $statement->execute([$teamId, $userId]);
        $envelopeId = (int) $statement->fetchColumn();
        $envelopePath = "documents/{$teamId}/envelope-original-{$suffix}.pdf";
        $setup->prepare("INSERT INTO documents (envelope_id, original_name, storage_path, size_bytes, page_count, original_sha256, created_at, updated_at) VALUES (?, 'original.pdf', ?, 100, 1, ?, NOW(), NOW())")
            ->execute([$envelopeId, $envelopePath, hash('sha256', "envelope-{$suffix}")]);

        $statement = $setup->prepare("INSERT INTO templates (team_id, sender_id, name, title, original_name, storage_path, size_bytes, page_count, original_sha256, created_at, updated_at) VALUES (?, ?, 'Replacement template', 'Replacement template', 'original.pdf', ?, 100, 1, ?, NOW(), NOW()) RETURNING id");
        $templatePath = "documents/{$teamId}/template-original-{$suffix}.pdf";
        $statement->execute([$teamId, $userId, $templatePath, hash('sha256', "template-{$suffix}")]);
        $templateId = (int) $statement->fetchColumn();
        Storage::put($envelopePath, PdfFixture::make());
        Storage::put($templatePath, PdfFixture::make());

        foreach ([2, 3, 4, 5] as $pages) {
            $source = tempnam(sys_get_temp_dir(), 'witness-race-');
            if ($source === false) {
                throw new RuntimeException('Could not create a replacement fixture.');
            }
            file_put_contents($source, PdfFixture::make($pages));
            $sources[] = $source;
        }

        $runOverlap = function (string $operation, int $recordId, string $lockTable, string $resultTable, string $whereColumn, array $files) use ($locker, $teamId, $userId, $setup): string {
            $locker->beginTransaction();
            $locker->prepare("SELECT id FROM {$lockTable} WHERE id = ? FOR UPDATE")->execute([$recordId]);
            $first = startSenderOperation($operation, $teamId, $userId, $recordId, ['path' => $files[0], 'name' => 'first.pdf']);
            $second = startSenderOperation($operation, $teamId, $userId, $recordId, ['path' => $files[1], 'name' => 'second.pdf']);
            Sleep::usleep(350000);
            expect($first->isRunning())->toBeTrue($first->getOutput().$first->getErrorOutput())
                ->and($second->isRunning())->toBeTrue($second->getOutput().$second->getErrorOutput());
            $locker->commit();

            expect(finishSenderOperation($first)['status'])->toBe('completed')
                ->and(finishSenderOperation($second)['status'])->toBe('completed');

            $statement = $setup->prepare("SELECT storage_path FROM {$resultTable} WHERE {$whereColumn} = ?");
            $statement->execute([$recordId]);

            return (string) $statement->fetchColumn();
        };

        $beforeEnvelope = Storage::allFiles("documents/{$teamId}");
        $currentEnvelope = $runOverlap('envelope-replace', $envelopeId, 'envelopes', 'documents', 'envelope_id', [$sources[0], $sources[1]]);
        $afterEnvelope = Storage::allFiles("documents/{$teamId}");
        Storage::assertMissing($envelopePath);
        Storage::assertExists($currentEnvelope);
        expect(count($afterEnvelope))->toBe(count($beforeEnvelope));

        $beforeTemplate = Storage::allFiles("documents/{$teamId}");
        $currentTemplate = $runOverlap('template-replace', $templateId, 'templates', 'templates', 'id', [$sources[2], $sources[3]]);
        $afterTemplate = Storage::allFiles("documents/{$teamId}");
        Storage::assertMissing($templatePath);
        Storage::assertExists($currentTemplate);
        expect(count($afterTemplate))->toBe(count($beforeTemplate));
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }
        foreach ($sources as $source) {
            @unlink($source);
        }
        if ($teamId !== null) {
            Storage::deleteDirectory("documents/{$teamId}");
            $setup->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);
        }
        if ($userId !== null) {
            $setup->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }
});

it('allows only one completion recovery worker to win the locked transition', function (): void {
    $setup = senderRaceConnection();
    $locker = senderRaceConnection();
    $suffix = bin2hex(random_bytes(6));
    $userId = $teamId = $envelopeId = null;

    try {
        $statement = $setup->prepare('INSERT INTO users (name, email, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) RETURNING id');
        $statement->execute(['Completion race sender', "completion-race-{$suffix}@example.test"]);
        $userId = (int) $statement->fetchColumn();
        $statement = $setup->prepare('INSERT INTO teams (name, created_at, updated_at) VALUES (?, NOW(), NOW()) RETURNING id');
        $statement->execute(["Completion race {$suffix}"]);
        $teamId = (int) $statement->fetchColumn();
        $setup->prepare('INSERT INTO team_user (team_id, user_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')->execute([$teamId, $userId]);
        $statement = $setup->prepare("INSERT INTO envelopes (team_id, sender_id, title, status, created_at, updated_at) VALUES (?, ?, 'Completion race', 'draft', NOW(), NOW()) RETURNING id");
        $statement->execute([$teamId, $userId]);
        $envelopeId = (int) $statement->fetchColumn();
        $setup->prepare("INSERT INTO documents (envelope_id, original_name, storage_path, size_bytes, page_count, original_sha256, created_at, updated_at) VALUES (?, 'race.pdf', ?, 100, 1, ?, NOW(), NOW())")
            ->execute([$envelopeId, "documents/completion-race-{$suffix}.pdf", hash('sha256', $suffix)]);
        $setup->prepare("INSERT INTO signers (envelope_id, name, email, status, position, signed_at, created_at, updated_at) VALUES (?, 'Race signer', ?, 'signed', 0, NOW(), NOW(), NOW())")
            ->execute([$envelopeId, "completion-signer-{$suffix}@example.test"]);
        $setup->beginTransaction();
        $setup->exec("SET LOCAL witness.envelope_transition = 'on'");
        $setup->prepare("UPDATE envelopes SET status = 'completion_failed', sent_at = NOW() WHERE id = ?")->execute([$envelopeId]);
        $setup->commit();

        $locker->beginTransaction();
        $locker->prepare('SELECT id FROM envelopes WHERE id = ? FOR UPDATE')->execute([$envelopeId]);
        $first = startSenderOperation('completion-retry', $teamId, $userId, $envelopeId);
        $second = startSenderOperation('completion-retry', $teamId, $userId, $envelopeId);
        Sleep::usleep(250000);
        expect($first->isRunning())->toBeTrue()->and($second->isRunning())->toBeTrue();
        $locker->commit();

        $results = [finishSenderOperation($first), finishSenderOperation($second)];
        $statuses = collect($results)->pluck('status')->sort()->values()->all();
        expect($statuses)->toBe(['completed', 'refused']);
        $statement = $setup->prepare('SELECT status FROM envelopes WHERE id = ?');
        $statement->execute([$envelopeId]);
        expect($statement->fetchColumn())->toBe('sent');
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }
        if ($envelopeId !== null) {
            $setup->beginTransaction();
            $setup->exec("SET LOCAL witness.envelope_transition = 'on'");
            $setup->prepare("UPDATE envelopes SET status = 'draft' WHERE id = ?")->execute([$envelopeId]);
            $setup->commit();
        }
        if ($teamId !== null) {
            $setup->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);
        }
        if ($userId !== null) {
            $setup->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }
});

it('serializes scheduled expiry and owner void to one terminal lifecycle result', function (): void {
    $setup = senderRaceConnection();
    $locker = senderRaceConnection();
    $suffix = bin2hex(random_bytes(6));
    $userId = $teamId = $envelopeId = null;

    try {
        $statement = $setup->prepare('INSERT INTO users (name, email, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) RETURNING id');
        $statement->execute(['Lifecycle race sender', "lifecycle-race-{$suffix}@example.test"]);
        $userId = (int) $statement->fetchColumn();
        $statement = $setup->prepare('INSERT INTO teams (name, created_at, updated_at) VALUES (?, NOW(), NOW()) RETURNING id');
        $statement->execute(["Lifecycle race {$suffix}"]);
        $teamId = (int) $statement->fetchColumn();
        $setup->prepare('INSERT INTO team_user (team_id, user_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')->execute([$teamId, $userId]);
        $statement = $setup->prepare("INSERT INTO envelopes (team_id, sender_id, title, status, expires_at, created_at, updated_at) VALUES (?, ?, 'Lifecycle race', 'draft', NOW() - INTERVAL '1 day', NOW(), NOW()) RETURNING id");
        $statement->execute([$teamId, $userId]);
        $envelopeId = (int) $statement->fetchColumn();
        $setup->prepare("INSERT INTO documents (envelope_id, original_name, storage_path, size_bytes, page_count, original_sha256, created_at, updated_at) VALUES (?, 'race.pdf', ?, 100, 1, ?, NOW(), NOW())")
            ->execute([$envelopeId, "documents/lifecycle-race-{$suffix}.pdf", hash('sha256', $suffix)]);
        $statement = $setup->prepare("INSERT INTO signers (envelope_id, name, email, status, position, created_at, updated_at) VALUES (?, 'Race signer', ?, 'pending', 0, NOW(), NOW()) RETURNING id");
        $statement->execute([$envelopeId, "lifecycle-signer-{$suffix}@example.test"]);
        $signerId = (int) $statement->fetchColumn();
        $setup->prepare("INSERT INTO fields (envelope_id, signer_id, page, type, required, position_x, position_y, width, height, created_at, updated_at) VALUES (?, ?, 1, 'signature', true, 0.1, 0.1, 0.2, 0.1, NOW(), NOW())")
            ->execute([$envelopeId, $signerId]);
        expect(finishSenderOperation(startSenderOperation('send', $teamId, $userId, $envelopeId))['status'])->toBe('completed');

        $locker->beginTransaction();
        $locker->prepare('SELECT id FROM envelopes WHERE id = ? FOR UPDATE')->execute([$envelopeId]);
        $expiry = startSenderOperation('lifecycle-due', $teamId, $userId, $envelopeId);
        $void = startSenderOperation('void', $teamId, $userId, $envelopeId);
        Sleep::usleep(250000);
        expect($expiry->isRunning())->toBeTrue()->and($void->isRunning())->toBeTrue();
        $locker->commit();

        $results = [finishSenderOperation($expiry), finishSenderOperation($void)];
        expect(collect($results)->where('status', 'completed')->count())
            ->toBeGreaterThanOrEqual(1, json_encode($results, JSON_THROW_ON_ERROR));
        $statement = $setup->prepare('SELECT status FROM envelopes WHERE id = ?');
        $statement->execute([$envelopeId]);
        expect($statement->fetchColumn())->toBeIn(['expired', 'voided']);
        $statement = $setup->prepare("SELECT COUNT(*) FROM audit_events WHERE envelope_id = ? AND type IN ('expired', 'voided')");
        $statement->execute([$envelopeId]);
        expect((int) $statement->fetchColumn())->toBe(1);
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }
        if ($envelopeId !== null) {
            $setup->beginTransaction();
            $setup->prepare("SELECT set_config('witness.purge_envelope', ?, true)")->execute([(string) $envelopeId]);
            $statement = $setup->prepare('SELECT verb_event_id FROM audit_events WHERE envelope_id = ?');
            $statement->execute([$envelopeId]);
            $eventIds = $statement->fetchAll(PDO::FETCH_COLUMN);
            $setup->prepare('DELETE FROM audit_events WHERE envelope_id = ?')->execute([$envelopeId]);
            foreach ($eventIds as $eventId) {
                $setup->prepare('DELETE FROM verb_state_events WHERE event_id = ?')->execute([$eventId]);
                $setup->prepare('DELETE FROM verb_events WHERE id = ?')->execute([$eventId]);
            }
            $setup->prepare('DELETE FROM envelopes WHERE id = ?')->execute([$envelopeId]);
            $setup->commit();
        }
        if ($teamId !== null) {
            $setup->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);
        }
        if ($userId !== null) {
            $setup->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }
});

it('autoprovisions exactly one personal team across concurrent first visits', function (): void {
    $setup = senderRaceConnection();
    $locker = senderRaceConnection();
    $suffix = bin2hex(random_bytes(6));
    $userId = null;

    try {
        $statement = $setup->prepare('INSERT INTO users (name, email, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) RETURNING id');
        $statement->execute(["Autoprovision {$suffix}", "autoprovision-{$suffix}@example.test"]);
        $userId = (int) $statement->fetchColumn();

        $locker->beginTransaction();
        $locker->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
        $first = startSenderOperation('team-autoprovision', 0, $userId, 0);
        $second = startSenderOperation('team-autoprovision', 0, $userId, 0);
        Sleep::usleep(350000);
        expect($first->isRunning())->toBeTrue($first->getOutput().$first->getErrorOutput())
            ->and($second->isRunning())->toBeTrue($second->getOutput().$second->getErrorOutput());
        $locker->commit();

        finishSenderOperation($first);
        finishSenderOperation($second);

        $statement = $setup->prepare('SELECT COUNT(*) FROM teams WHERE id IN (SELECT team_id FROM team_user WHERE user_id = ?)');
        $statement->execute([$userId]);
        expect((int) $statement->fetchColumn())->toBe(1);
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }
        if ($userId !== null) {
            $statement = $setup->prepare('SELECT team_id FROM team_user WHERE user_id = ?');
            $statement->execute([$userId]);
            $teamIds = $statement->fetchAll(PDO::FETCH_COLUMN);
            $setup->prepare('DELETE FROM team_user WHERE user_id = ?')->execute([$userId]);
            foreach ($teamIds as $teamId) {
                $setup->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);
            }
            $setup->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }
});

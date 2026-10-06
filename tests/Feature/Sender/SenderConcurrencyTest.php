<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
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
        'DB_DATABASE' => 'witness_app_test',
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
        usleep(250000);
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
        usleep(250000);
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
        usleep(250000);
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
        usleep(250000);
        expect($process->isRunning())->toBeTrue();
        $locker->commit();
        $result = finishSenderOperation($process);
        expect($result['status'])->toBe('refused')
            ->and($result['message'])->toContain('role and stay within a page');

        $locker->beginTransaction();
        $locker->prepare('SELECT id FROM templates WHERE id = ? FOR UPDATE')->execute([$roleTemplate]);
        $locker->prepare('DELETE FROM template_roles WHERE id = ?')->execute([$roleId]);
        $process = startSenderOperation('template-fields', $teamId, $userId, $roleTemplate, $templateField($roleId));
        usleep(250000);
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
            usleep(350000);
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

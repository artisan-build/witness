<?php

declare(strict_types=1);

use App\Listeners\CompleteReadyEnvelope;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;

function signingRaceConnection(): PDO
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

/** @param array<string, mixed> $payload */
function startSigningOperation(string $operation, int $teamId, int $userId, int $signerId, array $payload = []): Process
{
    $process = new Process([
        PHP_BINARY,
        base_path('tests/Support/concurrent-operation.php'),
        $operation,
        (string) $teamId,
        (string) $userId,
        (string) $signerId,
        json_encode($payload, JSON_THROW_ON_ERROR),
    ], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => 'witness_app_test',
        'DB_URL' => '',
        'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'database',
        'DB_QUEUE' => "signing-concurrency-{$operation}-{$signerId}",
        'SESSION_DRIVER' => 'array',
    ]);
    $process->setTimeout(15);
    $process->start();

    return $process;
}

/** @return array<string, mixed> */
function finishSigningOperation(Process $process): array
{
    $process->wait();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('serializes signing against decline and records exactly one terminal outcome', function (): void {
    $setup = signingRaceConnection();
    $locker = signingRaceConnection();
    $suffix = bin2hex(random_bytes(6));
    $userId = $teamId = $envelopeId = null;
    $verbEventIds = [];
    $completionQueue = null;

    try {
        $statement = $setup->prepare('INSERT INTO users (name, email, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) RETURNING id');
        $statement->execute(['Signing race sender', "signing-race-{$suffix}@example.test"]);
        $userId = (int) $statement->fetchColumn();
        $statement = $setup->prepare('INSERT INTO teams (name, created_at, updated_at) VALUES (?, NOW(), NOW()) RETURNING id');
        $statement->execute(["Signing race {$suffix}"]);
        $teamId = (int) $statement->fetchColumn();
        $setup->prepare('INSERT INTO team_user (team_id, user_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')->execute([$teamId, $userId]);
        $statement = $setup->prepare("INSERT INTO envelopes (team_id, sender_id, title, status, created_at, updated_at) VALUES (?, ?, 'Signing race', 'draft', NOW(), NOW()) RETURNING id");
        $statement->execute([$teamId, $userId]);
        $envelopeId = (int) $statement->fetchColumn();
        $setup->prepare("INSERT INTO documents (envelope_id, original_name, storage_path, size_bytes, page_count, original_sha256, created_at, updated_at) VALUES (?, 'race.pdf', 'documents/race.pdf', 100, 1, ?, NOW(), NOW())")
            ->execute([$envelopeId, hash('sha256', "signing-race-{$suffix}")]);
        $statement = $setup->prepare("INSERT INTO signers (envelope_id, name, email, status, position, created_at, updated_at) VALUES (?, 'Race signer', ?, 'pending', 0, NOW(), NOW()) RETURNING id");
        $statement->execute([$envelopeId, "race-signer-{$suffix}@example.test"]);
        $signerId = (int) $statement->fetchColumn();
        $completionQueue = "signing-concurrency-sign-{$signerId}";
        $statement = $setup->prepare("INSERT INTO fields (envelope_id, signer_id, page, type, required, position_x, position_y, width, height, created_at, updated_at) VALUES (?, ?, 1, 'signature', true, 0.1, 0.1, 0.2, 0.1, NOW(), NOW()) RETURNING id");
        $statement->execute([$envelopeId, $signerId]);
        $fieldId = (int) $statement->fetchColumn();

        expect(finishSigningOperation(startSigningOperation('send', $teamId, $userId, $envelopeId))['status'])->toBe('completed');

        $setup->beginTransaction();
        $setup->exec("SET LOCAL witness.signer_progress_write = 'on'");
        $setup->prepare("UPDATE signers SET status = 'viewed', viewed_at = NOW(), otp_verified_at = NOW(), consented_at = NOW() WHERE id = ?")->execute([$signerId]);
        $setup->exec("SET LOCAL witness.field_response_write = 'on'");
        $setup->prepare('UPDATE fields SET value = ?::jsonb, completed_at = NOW() WHERE id = ?')->execute([json_encode(['kind' => 'typed', 'text' => 'Race signer'], JSON_THROW_ON_ERROR), $fieldId]);
        $setup->commit();

        $locker->beginTransaction();
        $locker->prepare('SELECT id FROM envelopes WHERE id = ? FOR UPDATE')->execute([$envelopeId]);
        $sign = startSigningOperation('sign', $teamId, $userId, $signerId);
        $decline = startSigningOperation('decline', $teamId, $userId, $signerId, ['reason' => 'Race decline']);
        Sleep::usleep(350000);
        expect($sign->isRunning())->toBeTrue($sign->getOutput().$sign->getErrorOutput())
            ->and($decline->isRunning())->toBeTrue($decline->getOutput().$decline->getErrorOutput());
        $locker->commit();

        $signResult = finishSigningOperation($sign);
        $results = [$signResult, finishSigningOperation($decline)];
        expect(collect($results)->where('status', 'completed'))->toHaveCount(1)
            ->and(collect($results)->where('status', 'refused'))->toHaveCount(1);

        $statement = $setup->prepare('SELECT payload FROM jobs WHERE queue = ?');
        $statement->execute([$completionQueue]);
        $completionJobs = $statement->fetchAll(PDO::FETCH_COLUMN);
        expect($completionJobs)->toHaveCount($signResult['status'] === 'completed' ? 1 : 0);
        if ($completionJobs !== []) {
            /** @var array{displayName?: mixed} $payload */
            $payload = json_decode((string) $completionJobs[0], true, flags: JSON_THROW_ON_ERROR);
            expect($payload['displayName'] ?? null)->toBe(CompleteReadyEnvelope::class);
        }

        $statement = $setup->prepare('SELECT status FROM signers WHERE id = ?');
        $statement->execute([$signerId]);
        $signerStatus = $statement->fetchColumn();
        $statement = $setup->prepare("SELECT COUNT(*) FROM audit_events WHERE envelope_id = ? AND type IN ('signed', 'declined')");
        $statement->execute([$envelopeId]);
        expect((int) $statement->fetchColumn())->toBe(1)
            ->and($signerStatus)->toBeIn(['signed', 'declined']);
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }
        if ($completionQueue !== null) {
            $setup->prepare('DELETE FROM jobs WHERE queue = ?')->execute([$completionQueue]);
        }
        if ($envelopeId !== null) {
            $statement = $setup->prepare('SELECT verb_event_id FROM audit_events WHERE envelope_id = ?');
            $statement->execute([$envelopeId]);
            $verbEventIds = array_map(intval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
            $setup->beginTransaction();
            $setup->exec("SET LOCAL witness.purge_envelope = '{$envelopeId}'");
            $setup->prepare('DELETE FROM audit_events WHERE envelope_id = ?')->execute([$envelopeId]);
            $setup->exec("SET LOCAL witness.envelope_transition = 'on'");
            $setup->prepare("UPDATE envelopes SET status = 'draft' WHERE id = ?")->execute([$envelopeId]);
            $setup->prepare('DELETE FROM envelopes WHERE id = ?')->execute([$envelopeId]);
            $setup->commit();
        }
        foreach ($verbEventIds as $eventId) {
            $setup->prepare('DELETE FROM verb_state_events WHERE event_id = ?')->execute([$eventId]);
            $setup->prepare('DELETE FROM verb_events WHERE id = ?')->execute([$eventId]);
        }
        if ($teamId !== null) {
            $setup->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);
        }
        if ($userId !== null) {
            $setup->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }
});

it('allows one invitation delivery generation across concurrent send attempts', function (): void {
    $setup = signingRaceConnection();
    $locker = signingRaceConnection();
    $suffix = bin2hex(random_bytes(6));
    $userId = $teamId = $envelopeId = null;
    $verbEventIds = [];

    try {
        $statement = $setup->prepare('INSERT INTO users (name, email, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) RETURNING id');
        $statement->execute(['Mail race sender', "mail-race-{$suffix}@example.test"]);
        $userId = (int) $statement->fetchColumn();
        $statement = $setup->prepare('INSERT INTO teams (name, created_at, updated_at) VALUES (?, NOW(), NOW()) RETURNING id');
        $statement->execute(["Mail race {$suffix}"]);
        $teamId = (int) $statement->fetchColumn();
        $setup->prepare('INSERT INTO team_user (team_id, user_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')->execute([$teamId, $userId]);
        $statement = $setup->prepare("INSERT INTO envelopes (team_id, sender_id, title, status, created_at, updated_at) VALUES (?, ?, 'Mail race', 'draft', NOW(), NOW()) RETURNING id");
        $statement->execute([$teamId, $userId]);
        $envelopeId = (int) $statement->fetchColumn();
        $setup->prepare("INSERT INTO documents (envelope_id, original_name, storage_path, size_bytes, page_count, original_sha256, created_at, updated_at) VALUES (?, 'race.pdf', 'documents/race.pdf', 100, 1, ?, NOW(), NOW())")
            ->execute([$envelopeId, hash('sha256', "mail-race-{$suffix}")]);
        $statement = $setup->prepare("INSERT INTO signers (envelope_id, name, email, status, position, created_at, updated_at) VALUES (?, 'Race signer', ?, 'pending', 0, NOW(), NOW()) RETURNING id");
        $statement->execute([$envelopeId, "mail-race-signer-{$suffix}@example.test"]);
        $signerId = (int) $statement->fetchColumn();
        $setup->prepare("INSERT INTO fields (envelope_id, signer_id, page, type, required, position_x, position_y, width, height, created_at, updated_at) VALUES (?, ?, 1, 'signature', true, 0.1, 0.1, 0.2, 0.1, NOW(), NOW())")
            ->execute([$envelopeId, $signerId]);

        $locker->beginTransaction();
        $locker->prepare('SELECT id FROM envelopes WHERE id = ? FOR UPDATE')->execute([$envelopeId]);
        $first = startSigningOperation('send', $teamId, $userId, $envelopeId);
        $second = startSigningOperation('send', $teamId, $userId, $envelopeId);
        Sleep::usleep(350000);
        expect($first->isRunning())->toBeTrue($first->getOutput().$first->getErrorOutput())
            ->and($second->isRunning())->toBeTrue($second->getOutput().$second->getErrorOutput());
        $locker->commit();

        $results = [finishSigningOperation($first), finishSigningOperation($second)];
        expect(collect($results)->where('status', 'completed'))->toHaveCount(1)
            ->and(collect($results)->where('status', 'refused'))->toHaveCount(1);
        $statement = $setup->prepare('SELECT COUNT(*) FROM mail_deliveries WHERE envelope_id = ?');
        $statement->execute([$envelopeId]);
        expect((int) $statement->fetchColumn())->toBe(1);
        $statement = $setup->prepare("SELECT COUNT(*) FROM mail_deliveries WHERE envelope_id = ? AND status = 'sent'");
        $statement->execute([$envelopeId]);
        expect((int) $statement->fetchColumn())->toBe(1);
        $statement = $setup->prepare("SELECT COUNT(*) FROM audit_events WHERE envelope_id = ? AND type = 'email_sent'");
        $statement->execute([$envelopeId]);
        expect((int) $statement->fetchColumn())->toBe(1);
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }
        if ($envelopeId !== null) {
            $statement = $setup->prepare('SELECT verb_event_id FROM audit_events WHERE envelope_id = ?');
            $statement->execute([$envelopeId]);
            $verbEventIds = array_map(intval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
            $setup->beginTransaction();
            $setup->exec("SET LOCAL witness.purge_envelope = '{$envelopeId}'");
            $setup->prepare('DELETE FROM audit_events WHERE envelope_id = ?')->execute([$envelopeId]);
            $setup->exec("SET LOCAL witness.envelope_transition = 'on'");
            $setup->prepare("UPDATE envelopes SET status = 'draft' WHERE id = ?")->execute([$envelopeId]);
            $setup->prepare('DELETE FROM envelopes WHERE id = ?')->execute([$envelopeId]);
            $setup->commit();
        }
        foreach ($verbEventIds as $eventId) {
            $setup->prepare('DELETE FROM verb_state_events WHERE event_id = ?')->execute([$eventId]);
            $setup->prepare('DELETE FROM verb_events WHERE id = ?')->execute([$eventId]);
        }
        if ($teamId !== null) {
            $setup->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);
        }
        if ($userId !== null) {
            $setup->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }
});

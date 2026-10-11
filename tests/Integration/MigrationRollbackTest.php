<?php

declare(strict_types=1);

use App\Models\Envelope;
use App\Models\Team;
use Database\Factories\UserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

afterEach(function (): void {
    Artisan::call('migrate', ['--database' => 'pgsql', '--force' => true]);
});

it('restores working guards after rolling back the lifecycle migration', function (): void {
    expect(Artisan::call('migrate:fresh', [
        '--database' => 'pgsql',
        '--force' => true,
    ]))->toBe(0)
        ->and(Schema::hasColumn('envelopes', 'reminder_interval_days'))->toBeTrue()
        ->and(DB::scalar("SELECT COUNT(*) FROM pg_proc WHERE proname = 'witness_purge_matches'"))->toBe(1);

    expect(Artisan::call('migrate:rollback', [
        '--database' => 'pgsql',
        '--force' => true,
        '--path' => 'database/migrations/2026_10_06_000006_add_lifecycle_state_and_purge_guards.php',
    ]))->toBe(0);

    expect(Schema::hasColumn('envelopes', 'reminder_interval_days'))->toBeFalse()
        ->and(DB::scalar("SELECT COUNT(*) FROM pg_proc WHERE proname = 'witness_purge_matches'"))->toBe(0);

    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);
    $envelope = Envelope::factory()->forOwner($team, $sender)->create();
    $signer = $envelope->signers()->create([
        'name' => 'Alex Signer',
        'email' => 'alex-rollback@example.test',
        'position' => 0,
    ]);

    $envelope->update(['title' => 'Renamed after rollback']);
    expect($envelope->refresh()->title)->toBe('Renamed after rollback');

    $signer->delete();
    expect($signer->fresh())->toBeNull()
        ->and($envelope->refresh()->exists)->toBeTrue();
});

it('still refuses non-draft envelope deletion after the lifecycle rollback restores guards', function (): void {
    expect(Artisan::call('migrate:fresh', [
        '--database' => 'pgsql',
        '--force' => true,
    ]))->toBe(0);

    expect(Artisan::call('migrate:rollback', [
        '--database' => 'pgsql',
        '--force' => true,
        '--path' => 'database/migrations/2026_10_06_000006_add_lifecycle_state_and_purge_guards.php',
    ]))->toBe(0);

    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);
    $envelope = Envelope::factory()->forOwner($team, $sender)->sent()->create();

    expect(fn () => $envelope->delete())->toThrow(QueryException::class)
        ->and($envelope->refresh()->exists)->toBeTrue();
});

<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Envelope;
use App\Models\Field;
use App\Models\Team;
use App\Services\PdfDocumentService;
use App\States\EnvelopeState;
use ArtisanBuild\BuiltForCloud\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Thunk\Verbs\Lifecycle\StateManager;

final readonly class PurgeEnvelope
{
    public function __construct(private PdfDocumentService $pdfs) {}

    public function handle(Team $team, User $sender, int $envelopeId): void
    {
        $paths = [];
        try {
            $paths = DB::transaction(function () use ($team, $sender, $envelopeId): array {
                $envelope = Envelope::query()->forTeam($team)->where('sender_id', $sender->getKey())
                    ->lockForUpdate()->findOrFail($envelopeId);
                if (! $envelope->status->isDeletable()) {
                    throw new DomainException('Only completed, voided, or declined envelopes can be deleted.');
                }
                $document = $envelope->document()->lockForUpdate()->first();
                $paths = array_filter([$document?->storage_path, $document?->final_storage_path]);
                Field::query()->where('envelope_id', $envelopeId)->lockForUpdate()->get()->each(function (Field $field) use (&$paths): void {
                    if (($field->value['kind'] ?? null) === 'drawn' && is_string($field->value['path'] ?? null)) {
                        $paths[] = $field->value['path'];
                    }
                });

                DB::select("SELECT set_config('witness.purge_envelope', ?, true)", [(string) $envelopeId]);
                $eventIds = DB::table('audit_events')->where('envelope_id', $envelopeId)->pluck('verb_event_id');
                DB::table('audit_events')->where('envelope_id', $envelopeId)->delete();
                DB::table('verb_state_events')->whereIn('event_id', $eventIds)->delete();
                DB::table('verb_events')->whereIn('id', $eventIds)->delete();
                DB::table('verb_snapshots')->where('state_id', $envelopeId)->where('type', EnvelopeState::class)->delete();
                DB::table('envelopes')->where('id', $envelopeId)->delete();

                return array_values(array_unique($paths));
            });
        } finally {
            resolve(StateManager::class)->reset();
        }

        foreach ($paths as $path) {
            $this->pdfs->deleteOrQueue($path);
        }
    }
}

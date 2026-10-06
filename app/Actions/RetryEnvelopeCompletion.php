<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Events\EnvelopeReadyForCompletion;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class RetryEnvelopeCompletion
{
    public function handle(Team $team, User $sender, int $envelopeId): void
    {
        DB::transaction(function () use ($team, $sender, $envelopeId): void {
            $envelope = Envelope::query()->forTeam($team)->where('sender_id', $sender->getKey())->lockForUpdate()->findOrFail($envelopeId);
            $document = Document::query()->where('envelope_id', $envelopeId)->lockForUpdate()->firstOrFail();
            $signers = Signer::query()->where('envelope_id', $envelopeId)->orderBy('id')->lockForUpdate()->get();
            if ($envelope->status !== EnvelopeStatus::CompletionFailed || $document->final_storage_path !== null
                || $signers->isEmpty() || $signers->contains(fn (Signer $signer) => $signer->status !== SignerStatus::Signed)) {
                throw new DomainException('This envelope is not ready for completion recovery.');
            }
            try {
                DB::statement("SET LOCAL witness.envelope_transition = 'on'");
                DB::table('envelopes')->where('id', $envelopeId)->update(['status' => EnvelopeStatus::Sent->value, 'updated_at' => now()]);
            } finally {
                DB::statement("SET LOCAL witness.envelope_transition = 'off'");
            }
            DB::afterCommit(fn () => event(new EnvelopeReadyForCompletion($envelopeId)));
        });
    }
}

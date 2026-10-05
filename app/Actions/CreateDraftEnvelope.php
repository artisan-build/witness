<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Models\Envelope;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CreateDraftEnvelope
{
    public function handle(
        Team $team,
        User $sender,
        string $title,
        string $originalName,
        string $storagePath,
        int $sizeBytes,
        int $pageCount,
        string $originalSha256,
        ?string $message = null,
        ?CarbonInterface $expiresAt = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Envelope {
        if (! $team->hasMember($sender)) {
            throw new DomainException('The sender must belong to the envelope team.');
        }

        return DB::transaction(function () use (
            $team,
            $sender,
            $title,
            $originalName,
            $storagePath,
            $sizeBytes,
            $pageCount,
            $originalSha256,
            $message,
            $expiresAt,
            $ipAddress,
            $userAgent,
        ): Envelope {
            $envelope = Envelope::query()->create([
                'team_id' => $team->getKey(),
                'sender_id' => $sender->getKey(),
                'title' => $title,
                'message' => $message,
                'status' => EnvelopeStatus::Draft,
                'expires_at' => $expiresAt,
            ]);

            $envelope->document()->create([
                'original_name' => $originalName,
                'storage_path' => $storagePath,
                'mime_type' => 'application/pdf',
                'size_bytes' => $sizeBytes,
                'page_count' => $pageCount,
                'original_sha256' => $originalSha256,
            ]);

            EnvelopeLifecycleRecorded::record(
                envelope: $envelope,
                type: AuditEventType::Created,
                actorType: AuditActorType::Sender,
                actorId: (int) $sender->getKey(),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                payload: ['title' => $title],
            );

            return $envelope->refresh()->load('document');
        });
    }
}

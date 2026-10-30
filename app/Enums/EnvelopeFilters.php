<?php

declare(strict_types=1);

namespace App\Enums;

/** The groups a sender filters their envelopes by, each covering the statuses that call for the same next step. */
enum EnvelopeFilters: string
{
    case Drafts = 'drafts';
    case Waiting = 'waiting';
    case Attention = 'attention';
    case Completed = 'completed';
    case Closed = 'closed';

    /** The name shown in the sidebar and the page heading. */
    public function label(): string
    {
        return match ($this) {
            self::Drafts => 'Drafts',
            self::Waiting => 'Waiting for signatures',
            self::Attention => 'Needs attention',
            self::Completed => 'Completed',
            self::Closed => 'Voided and expired',
        };
    }

    /**
     * The envelope statuses this group covers.
     *
     * @return list<EnvelopeStatus>
     */
    public function statuses(): array
    {
        return match ($this) {
            self::Drafts => [EnvelopeStatus::Draft],
            self::Waiting => [EnvelopeStatus::Sent],
            self::Attention => [EnvelopeStatus::Declined, EnvelopeStatus::CompletionFailed],
            self::Completed => [EnvelopeStatus::Completed],
            self::Closed => [EnvelopeStatus::Voided, EnvelopeStatus::Expired],
        };
    }

    /** The group an envelope status belongs to. */
    public static function forStatus(EnvelopeStatus $status): self
    {
        return collect(self::cases())->first(fn (self $filter): bool => in_array($status, $filter->statuses(), true));
    }
}

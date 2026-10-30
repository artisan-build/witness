<?php

declare(strict_types=1);

namespace App\Enums;

/** The steps a draft envelope moves through before it is sent, in order. */
enum EnvelopeSteps: string
{
    case Details = 'details';
    case Signers = 'signers';
    case Fields = 'fields';
    case Review = 'review';

    /** The name shown in the step bar. */
    public function label(): string
    {
        return match ($this) {
            self::Details => 'Document',
            self::Signers => 'Signers',
            self::Fields => 'Fields',
            self::Review => 'Review and send',
        };
    }

    /** The step after this one, or null for the last. */
    public function next(): ?self
    {
        $cases = self::cases();

        return $cases[array_search($this, $cases, true) + 1] ?? null;
    }

    /**
     * Every step's query-string value.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $step): string => $step->value, self::cases());
    }
}

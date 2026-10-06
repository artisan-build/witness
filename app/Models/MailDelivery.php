<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property MailDeliveryPurpose $purpose
 * @property MailDeliveryStatus $status
 */
class MailDelivery extends Model
{
    protected $fillable = [
        'purpose',
        'generation',
        'status',
        'attempted_at',
        'sent_at',
        'unknown_at',
    ];

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<Signer, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(Signer::class);
    }

    protected function casts(): array
    {
        return [
            'purpose' => MailDeliveryPurpose::class,
            'generation' => 'integer',
            'status' => MailDeliveryStatus::class,
            'attempted_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'unknown_at' => 'immutable_datetime',
        ];
    }
}

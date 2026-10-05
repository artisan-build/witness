<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnvelopeStatus;
use ArtisanBuild\BuiltForCloud\User;
use Database\Factories\EnvelopeFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @mixin IdeHelperEnvelope
 */
class Envelope extends Model
{
    /** @use HasFactory<EnvelopeFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'sender_id',
        'title',
        'message',
        'status',
        'expires_at',
        'sent_at',
        'completed_at',
    ];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return HasOne<Document, $this> */
    public function document(): HasOne
    {
        return $this->hasOne(Document::class);
    }

    /** @return HasMany<Signer, $this> */
    public function signers(): HasMany
    {
        return $this->hasMany(Signer::class);
    }

    /** @return HasMany<Field, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(Field::class);
    }

    /** @return HasMany<AuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class)->orderBy('sequence');
    }

    /** @param Builder<Envelope> $query */
    #[Scope]
    protected function forTeam(Builder $query, Team|int $team): void
    {
        $query->where('team_id', $team instanceof Team ? $team->getKey() : $team);
    }

    protected function casts(): array
    {
        return [
            'status' => EnvelopeStatus::class,
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}

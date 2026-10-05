<?php

declare(strict_types=1);

namespace App\Models;

use ArtisanBuild\BuiltForCloud\User;
use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperTemplate
 */
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'sender_id',
        'name',
        'title',
        'message',
        'original_name',
        'storage_path',
        'size_bytes',
        'page_count',
        'original_sha256',
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

    /** @return HasMany<TemplateRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(TemplateRole::class)->orderBy('position');
    }

    /** @return HasMany<TemplateField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class);
    }

    /** @param Builder<Template> $query */
    #[Scope]
    protected function forTeam(Builder $query, Team|int $team): void
    {
        $query->where('team_id', $team instanceof Team ? $team->getKey() : $team);
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'page_count' => 'integer',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TemplateRoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TemplateRole extends Model
{
    /** @use HasFactory<TemplateRoleFactory> */
    use HasFactory;

    protected $fillable = ['template_id', 'label', 'position'];

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return HasMany<TemplateField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class);
    }

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FieldType;
use Database\Factories\TemplateFieldFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperTemplateField
 */
class TemplateField extends Model
{
    /** @use HasFactory<TemplateFieldFactory> */
    use HasFactory;

    protected $fillable = [
        'template_id',
        'template_role_id',
        'page',
        'type',
        'required',
        'position_x',
        'position_y',
        'width',
        'height',
    ];

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return BelongsTo<TemplateRole, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(TemplateRole::class, 'template_role_id');
    }

    protected static function booted(): void
    {
        static::saving(function (TemplateField $field): void {
            $field->template_id ??= TemplateRole::query()->findOrFail($field->template_role_id)->template_id;

            $belongs = TemplateRole::query()
                ->whereKey($field->template_role_id)
                ->where('template_id', $field->template_id)
                ->exists();

            if (! $belongs) {
                throw new DomainException('A template field role must belong to the same template.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => FieldType::class,
            'required' => 'boolean',
            'position_x' => 'decimal:10',
            'position_y' => 'decimal:10',
            'width' => 'decimal:10',
            'height' => 'decimal:10',
        ];
    }
}

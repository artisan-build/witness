<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class AppendOnlyBuilder extends Builder
{
    /** @param TModel $model */
    public function __construct(QueryBuilder $query, Model $model)
    {
        parent::__construct($query);
        $this->setModel($model);
    }

    public function update(array $values): never
    {
        throw new LogicException('Audit events are append-only and cannot be updated.');
    }

    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw new LogicException('Audit events are append-only and cannot be upserted.');
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw new LogicException('Audit events are append-only and cannot be updated.');
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw new LogicException('Audit events are append-only and cannot be updated.');
    }

    /** @param array<int, string>|string|null $column */
    public function touch($column = null): never
    {
        throw new LogicException('Audit events are append-only and cannot be updated.');
    }

    public function delete(): never
    {
        throw new LogicException('Audit events are append-only and cannot be deleted.');
    }

    public function forceDelete(): never
    {
        throw new LogicException('Audit events are append-only and cannot be deleted.');
    }
}

<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Traits;

use BlitzPHP\Contracts\Queue\QueueableCollection;
use BlitzPHP\Contracts\Queue\QueueableEntity;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection as WolkeCollection;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\AsPivot;
use BlitzPHP\Wolke\Relations\Pivot;
use Illuminate\Contracts\Database\ModelIdentifier;

/**
 * Remplace les entités / collections Wolke par des identifiants lors de la sérialisation, puis les recharge.
 */
trait SerializesAndRestoresModelIdentifiers
{
    /**
     * Prépare la valeur de propriété pour la sérialisation.
     */
    protected function getSerializedPropertyValue(mixed $value, bool $withRelations = true): mixed
    {
        if ($value instanceof QueueableCollection) {
            return (new ModelIdentifier(
                $value->getQueueableClass(),
                $value->getQueueableIds(),
                $withRelations ? $value->getQueueableRelations() : [],
                $value->getQueueableConnection(),
            ))->useCollectionClass(
                ($collectionClass = $value::class) !== WolkeCollection::class
                    ? $collectionClass
                    : null,
            );
        }

        if ($value instanceof QueueableEntity) {
            return new ModelIdentifier(
                $value::class,
                $value->getQueueableId(),
                $withRelations ? $value->getQueueableRelations() : [],
                $value->getQueueableConnection(),
            );
        }

        return $value;
    }

    /**
     * Restaure la valeur de propriété après désérialisation.
     */
    protected function getRestoredPropertyValue(mixed $value): mixed
    {
        if (! $value instanceof ModelIdentifier) {
            return $value;
        }

        return is_array($value->id)
            ? $this->restoreCollection($value)
            : $this->restoreModel($value);
    }

    /**
     * Restaure une collection enfilable.
     *
     * @param ModelIdentifier $value
     *
     * @return WolkeCollection
     */
    protected function restoreCollection($value)
    {
        $class = $value->getClass();

        if (! $class || count($value->id) === 0) {
            return null !== ($value->collectionClass ?? null)
                ? new $value->collectionClass()
                : new WolkeCollection();
        }

        $collection = $this->getQueryForModelRestoration(
            (new $class())->setConnection($value->connection),
            $value->id,
        )->useWritePdo()->get();

        if (is_a($class, Pivot::class, true) || in_array(AsPivot::class, class_uses($class), true)) {
            return $collection;
        }

        $collection = $collection->keyBy->getKey();

        $collectionClass = $collection::class;

        return (new $collectionClass(
            (new Collection($value->id))
                ->map(fn ($id) => $collection[$id] ?? null)
                ->filter(),
        ))->loadMissing($value->relations ?? []);
    }

    /**
     * Restaure le modèle à partir de son identifiant.
     *
     * @param ModelIdentifier $value
     *
     * @return Model
     */
    public function restoreModel($value)
    {
        return $this->getQueryForModelRestoration(
            (new ($value->getClass()))->setConnection($value->connection),
            $value->id,
        )->useWritePdo()->firstOrFail()->loadMissing($value->relations ?? []);
    }

    /**
     * Retourne la requête de restauration du modèle.
     *
     * @template TModel of \BlitzPHP\Wolke\Model
     *
     * @param TModel $model
     *
     * @return Builder<TModel>
     */
    protected function getQueryForModelRestoration($model, array|int $ids)
    {
        return $model->newQueryForRestoration($ids);
    }
}

<?php

namespace Kyslik\ColumnSortable;

use BadMethodCallException;
use Nova\Database\ORM\Relations\BelongsTo;
use Nova\Database\ORM\Relations\HasOne;
use Nova\Support\Facades\Config;
use Nova\Support\Facades\Request;
use Nova\Support\Facades\Schema;
use Kyslik\ColumnSortable\Exceptions\ColumnSortableException;

/**
 * Sortable trait.
 */
trait Sortable
{

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @param array|null                         $defaultSortParameters
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function scopeSortable($query, $defaultSortParameters = null)
    {
        if (Request::filled('sort') && Request::filled('order')) {
            return $this->queryOrderBuilder($query, Request::only(['sort', 'order']));
        } elseif ( ! is_null($defaultSortParameters)) {
            $defaultSortArray = $this->formatToSortParameters($defaultSortParameters);

            if (Config::get('columnsortable.allow_request_modification', true) && ! empty($defaultSortArray)) {
                Request::merge($defaultSortArray);
            }

            return $this->queryOrderBuilder($query, $defaultSortArray);
        } else {
            return $query;
        }
    }


    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @param array                              $sortParameters
     *
     * @return \Illuminate\Database\Query\Builder
     *
     * @throws ColumnSortableException
     */
    private function queryOrderBuilder($query, array $sortParameters)
    {
        $model = $this;

        list($column, $direction) = $this->parseSortParameters($sortParameters);

        if (is_null($column)) {
            return $query;
        }

        $explodeResult = SortableLink::explodeSortParameter($column);
        if ( ! empty($explodeResult)) {
            $relationName = $explodeResult[0];
            $column       = $explodeResult[1];

            try {
                $relation = $query->getRelation($relationName);
                $query    = $this->queryJoinBuilder($query, $relation);
            } catch (BadMethodCallException $e) {
                throw new ColumnSortableException($relationName, 1, $e);
            } catch (\Exception $e) {
                throw new ColumnSortableException($relationName, 2, $e);
            }

            $model = $relation->getRelated();
        }

        if (method_exists($model, camel_case($column).'Sortable')) {
            return call_user_func_array([$model, camel_case($column).'Sortable'], [$query, $direction]);
        }

        if (isset($model->sortableAs) && in_array($column, $model->sortableAs)) {
            $query = $query->orderBy($column, $direction);
        } elseif ($this->columnExists($model, $column)) {
            $column = $model->getTable().'.'.$column;
            $query  = $query->orderBy($column, $direction);
        }

        return $query;
    }


    /**
     * @param array $sortParameters
     *
     * @return array
     */
    private function parseSortParameters(array $sortParameters)
    {
        $column = array_get($sortParameters, 'sort');
        if (empty($column)) {
            return [null, null];
        }

        $direction = array_get($sortParameters, 'order', []);
        if ( ! in_array($direction, ['asc', 'desc'])) {
            $direction = Config::get('columnsortable.default_direction', 'asc');
        }

        return [$column, $direction];
    }


    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @param                                    $relation
     *
     * @return \Illuminate\Database\Query\Builder
     *
     * @throws \Exception
     */
    private function queryJoinBuilder($query, $relation)
    {
        $relatedTable = $relation->getRelated()->getTable();
        $parentTable  = $relation->getParent()->getTable();

        if ($parentTable === $relatedTable) {
            $query       = $query->from($parentTable.' as parent_'.$parentTable);
            $parentTable = 'parent_'.$parentTable;
            $relation->getParent()->setTable($parentTable);
        }

        if ($relation instanceof HasOne) {
            $relatedPrimaryKey = $relation->getQualifiedForeignKeyName();
            $parentPrimaryKey  = $relation->getQualifiedParentKeyName();

            return $this->formJoin($query, $parentTable, $relatedTable, $parentPrimaryKey, $relatedPrimaryKey);
        } elseif ($relation instanceof BelongsTo) {
            $relatedPrimaryKey = $relation->getQualifiedOwnerKeyName();
            $parentPrimaryKey  = $relation->getQualifiedForeignKey();

            return $this->formJoin($query, $parentTable, $relatedTable, $parentPrimaryKey, $relatedPrimaryKey);
        } else {
            throw new \Exception();
        }
    }


    /**
     * @param $model
     * @param $column
     *
     * @return bool
     */
    private function columnExists($model, $column)
    {
        return (isset($model->sortable)) ? in_array($column, $model->sortable) :
            Schema::hasColumn($model->getTable(), $column);
    }


    /**
     * @param array|string $sort
     *
     * @return array
     */
    private function formatToSortParameters($sort)
    {
        if (empty($sort)) {
            return [];
        }

        $configDefaultOrder = Config::get('columnsortable.default_direction', 'asc');

        if (is_string($sort)) {
            return ['sort' => $sort, 'order' => $configDefaultOrder];
        }

        reset($sort);
        $each = each($sort);

        return ($each[0] === 0) ? ['sort' => $each[1], 'order' => $configDefaultOrder] : [
            'sort'  => $each[0],
            'order' => $each[1],
        ];
    }


    /**
     * @param $query
     * @param $parentTable
     * @param $relatedTable
     * @param $parentPrimaryKey
     * @param $relatedPrimaryKey
     *
     * @return mixed
     */
    private function formJoin($query, $parentTable, $relatedTable, $parentPrimaryKey, $relatedPrimaryKey)
    {
        $joinType = Config::get('columnsortable.join_type', 'leftJoin');

        return $query->select($parentTable.'.*')->{$joinType}($relatedTable, $parentPrimaryKey, '=', $relatedPrimaryKey);
    }
}

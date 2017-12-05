<?php namespace GeneaLabs\LaravelModelCaching;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class CacheKey
{
    protected $eagerLoad;
    protected $model;
    protected $query;

    public function __construct(array $eagerLoad, Model $model, Builder $query)
    {
        $this->eagerLoad = $eagerLoad;
        $this->model = $model;
        $this->query = $query;
    }

    public function make(array $columns = ['*'], $idColumn = null) : string
    {
        $key = $this->getModelSlug();
        $key .= $this->getIdColumn($idColumn ?: '');
        $key .= $this->getQueryColumns($columns);
        $key .= $this->getWhereClauses();
        $key .= $this->getWithModels();
        $key .= $this->getOrderByClauses();
        $key .= $this->getOffsetClause();
        $key .= $this->getLimitClause();

        return $key;
    }

    protected function getIdColumn(string $idColumn) : string
    {
        return $idColumn ? "_{$idColumn}" : '';
    }

    protected function getLimitClause() : string
    {
        if (! $this->query->limit) {
            return '';
        }

        return "-limit_{$this->query->limit}";
    }

    protected function getModelSlug() : string
    {
        return str_slug(get_class($this->model));
    }

    protected function getOffsetClause() : string
    {
        if (! $this->query->offset) {
            return '';
        }

        return "-offset_{$this->query->offset}";
    }

    protected function getOrderByClauses() : string
    {
        $orders = collect($this->query->orders);

        return $orders
            ->reduce(function ($carry, $order) {
                if ($order['type'] === 'Raw') {
                    return $carry . '_orderByRaw_' . str_slug($order['sql']);
                }

                return $carry . '_orderBy_' . $order['column'] . '_' . $order['direction'];
            })
            ?: '';
    }

    protected function getQueryColumns(array $columns) : string
    {
        if ($columns === ['*'] || $columns === []) {
            return '';
        }

        return '_' . implode('_', $columns);
    }

    protected function getTypeClause($where) : string
    {
        return in_array($where['type'], ['In', 'Null', 'NotNull'])
            ? strtolower($where['type'])
            : '';
    }

    protected function getValuesClause(array $where = null) : string
    {
        return is_array(array_get($where, 'values'))
            ? '_' . implode('_', $where['values'])
            : '';
    }

    protected function getWhereClauses(array $wheres = []) : string
    {
        return $this->getWheres($wheres)
            ->reduce(function ($carry, $where) {
                $value = $this->getNestedClauses($where);
                $value .= $this->getColumnClauses($where);
                $value .= $this->getRawClauses($where);
                $value .= $this->getOtherClauses($where, $carry);

                return $value;
            })
            . '';
    }

    protected function getNestedClauses(array $where) : string
    {
        if (! in_array($where['type'], ['Exists', 'Nested', 'NotExists'])) {
            return '';
        }

        return '_' . strtolower($where['type']) . $this->getWhereClauses($where['query']->wheres);
    }

    protected function getColumnClauses(array $where) : string
    {
        if ($where['type'] !== 'Column') {
            return '';
        }

        return "_{$where['boolean']}_{$where['first']}_{$where['operator']}_{$where['second']}";
    }

    protected function getRawClauses(array $where) : string
    {
        if ($where['type'] !== 'raw') {
            return '';
        }

        return "_{$where['boolean']}_" . str_slug($where['sql']);
    }

    protected function getOtherClauses(array $where, string $carry = null) : string
    {
        if (in_array($where['type'], ['Exists', 'Nested', 'NotExists', 'raw', 'Column'])) {
            return '';
        }

        $value = array_get($where, 'value');
        $value .= $this->getTypeClause($where);
        $value .= $this->getValuesClause($where);

        return "{$carry}-{$where['column']}_{$value}";
    }

    protected function getWheres(array $wheres) : Collection
    {
        $wheres = collect($wheres);

        if ($wheres->isEmpty()) {
            $wheres = collect($this->query->wheres);
        }

        return $wheres;
    }

    protected function getWithModels() : string
    {
        $eagerLoads = collect($this->eagerLoad);

        if ($eagerLoads->isEmpty()) {
            return '';
        }

        return '-' . implode('-', $eagerLoads->keys()->toArray());
    }
}

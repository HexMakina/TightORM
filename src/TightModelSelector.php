<?php

namespace HexMakina\TightORM;

use HexMakina\BlackBox\ORM\ModelInterface;
use HexMakina\BlackBox\Database\SelectInterface;
use HexMakina\Crudites\Queries\AutoJoin;

class TightModelSelector
{

    private \HexMakina\BlackBox\ORM\ModelInterface $model;

    private string $model_class;

    private $model_table;

    private \HexMakina\BlackBox\Database\SelectInterface $statement;

    public function __construct(ModelInterface $model)
    {
        $this->model = $model;
        $this->model_class = get_class($model);
        $this->model_table = $this->model_class::table();
        $this->statement = $this->model_table->select();
    }

    public function model(): ModelInterface
    {
        return $this->model;
    }

    public function class(): string
    {
        return $this->model_class;
    }

    public function statement(): SelectInterface
    {
        return $this->statement;
    }

    public function select($filters = [], $options = []): SelectInterface
    {
        $this->statement = $this->model_table->select(null, $options['table_alias'] ?? get_class($this->model)::tableAlias());
        // $this->statement()->tableAlias($options['table_alias'] ?? get_class($this->model)::tableAlias());

        if (!isset($options['eager']) || $options['eager'] !== false) {
            AutoJoin::eager($this->statement());
        }

        if (isset($options['order_by'])) {
            $this->option_order_by($options['order_by']);
        }

        if (isset($options['limit']) && is_array($options['limit'])) { // TODO this doesn't need an array. limit function works it out itself
            $this->statement()->limit($options['limit'][1], $options['limit'][0]);
        }

        $this->filter_with_fields($filters);

        if (is_subclass_of($this->model(), '\HexMakina\kadro\Models\Interfaces\EventInterface')) {
            $this->filter_event($filters['date_start'] ?? null, $filters['date_stop'] ?? null);
            $this->statement()->orderBy([$this->model()->event_field(), 'DESC']);
        }

        if (isset($filters['content'])) {
            $this->statement()->whereFilterContent($filters['content']);
        }

        if (isset($filters['ids'])) {
            $this->filter_with_ids($filters['ids']);
        }

        return $this->statement();
    }

    public function option_order_by($order_bys): void
    {
        if (is_string($order_bys)) {
            $this->statement()->orderBy($order_bys);
        } elseif (is_array($order_bys)) { // TODO commenting required about the array situation
            foreach ($order_bys as $order_by) {
                if (!isset($order_by[2])) {
                    array_unshift($order_by, '');
                }

                list($order_table, $order_field, $order_direction) = $order_by;
                $this->statement()->orderBy([$order_table ?? '', $order_field, $order_direction]);
            }
        }
    }

    public function filter_event($date_start = null, $date_stop = null): void
    {
        if (!empty($date_start)) {
            $this->statement()->whereGTE($this->model()->event_field(), $date_start, $this->statement()->tableLabel(), ':filter_date_start');
        }

        if (!empty($date_stop)) {
            $this->statement()->whereLTE($this->model()->event_field(), $date_stop, $this->statement()->tableLabel(), ':filter_date_stop');
        }

      //
      // if(empty($options['order_by']))
      //   $this->statement()->orderBy([$this->model()->event_field(), 'DESC']);
    }

    public function filter_with_ids($ids): void
    {
        if (empty($ids)) {
            $this->statement()->where('1=0'); // TODO: this is a new low.. find another way to cancel query
        } else {
            $this->statement()->whereNumericIn('id', $ids);
        }
    }

    public function filter_with_fields($filters, $filter_mode = 'whereEQ'): void
    {
        foreach ($this->model_table->columns() as $column_name => $column) {
            if (!isset($filters[$column_name])) {
                continue;
            }
            if (!is_string($filters[$column_name])) {
                continue;
            }
            $this->statement()->$filter_mode($column_name, $filters[$column_name], $this->model_table->name());
        }
    }
}

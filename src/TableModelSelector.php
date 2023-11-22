<?php

namespace HexMakina\TightORM;

use HexMakina\BlackBox\ORM\ModelInterface;
use HexMakina\BlackBox\Database\SelectInterface;
use HexMakina\Crudites\Queries\AutoJoin;

class TableModelSelector
{
    private \HexMakina\BlackBox\ORM\ModelInterface $model;

    public function __construct(ModelInterface $model)
    {
        $this->model = $model;
    }

    public function select($filters = [], $options = []): SelectInterface
    {
        $class = get_class($this->model);
        $table = $class::table();

        $Query = $table->select(null, $class::tableAlias());


        if (!isset($options['eager']) || $options['eager'] !== false) {
            AutoJoin::eager($Query);
        }


        foreach ($table->columns() as $column_name => $column) {
            if (!isset($filters[$column_name])) {
                continue;
            }
            if (!is_string($filters[$column_name])) {
                continue;
            }
            $Query->whereEQ($column_name, $filters[$column_name]);
        }

        if (is_subclass_of($event = new $class(), '\HexMakina\kadro\Models\Interfaces\EventInterface')) {
            if (!empty($filters['date_start'])) {
                $Query->whereGTE($event->event_field(), $filters['date_start'], $Query->tableLabel(), ':filter_date_start');
            }

            if (!empty($filters['date_stop'])) {
                $Query->whereLTE($event->event_field(), $filters['date_stop'], $Query->tableLabel(), ':filter_date_stop');
            }

            if (empty($options['order_by'])) {
                $Query->orderBy([$event->event_field(), 'DESC']);
            }
        }

        if (isset($filters['content'])) {
            $Query->whereFilterContent($filters['content']);
        }

        if (isset($filters['ids'])) {
            if (empty($filters['ids'])) {
                $Query->where('1=0'); // TODO: this is a new low.. find another way to cancel query
            } else {
                $Query->whereNumericIn('id', $filters['ids'], $Query->tableLabel());
            }
        }

        if (isset($options['order_by'])) { // TODO commenting required about the array situation
            $order_by = $options['order_by'];

            if (is_string($order_by)) {
                $Query->orderBy($order_by);
            } elseif (is_array($order_by)) {
                foreach ($options['order_by'] as $order_by) {
                    if (!isset($order_by[2])) {
                        array_unshift($order_by, '');
                    }

                    list($order_table, $order_field, $order_direction) = $order_by;
                    $Query->orderBy([$order_table ?? '', $order_field, $order_direction]);
                }
            }
        }

        if (isset($options['limit']) && is_array($options['limit'])) {
            $Query->limit($options['limit'][1], $options['limit'][0]);
        }

        return $Query;
    }
}

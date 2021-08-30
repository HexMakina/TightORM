<?php

namespace HexMakina\TightORM;

use HexMakina\TightORM\Interfaces\ModelInterface;
use HexMakina\Crudites\Interfaces\SelectInterface;

class TableModelSelector
{
    private $model;

    public function __construct(ModelInterface $m)
    {
        $this->model = $m;
    }

    public function select($filters = [], $options = []): SelectInterface
    {
        $class = get_class($this->model);
        $table = $class::table();

        $Query = $table->select(null, $class::table_alias());


        if (!isset($options['eager']) || $options['eager'] !== false) {
            $Query->eager();
        }


        foreach ($table->columns() as $column_name => $column) {
            if (isset($filters[$column_name]) && is_string($filters[$column_name])) {
                $Query->aw_eq($column_name, $filters[$column_name]);
            }
        }

        if (is_subclass_of($event = new $class(), '\HexMakina\kadro\Models\Interfaces\EventInterface')) {
            if (!empty($filters['date_start'])) {
                $Query->aw_gte($event->event_field(), $filters['date_start'], $Query->table_label(), ':filter_date_start');
            }

            if (!empty($filters['date_stop'])) {
                $Query->aw_lte($event->event_field(), $filters['date_stop'], $Query->table_label(), ':filter_date_stop');
            }

            if (empty($options['order_by'])) {
                $Query->order_by([$event->event_field(), 'DESC']);
            }
        }

        if (isset($filters['content'])) {
            $Query->aw_filter_content($filters['content']);
        }

        if (isset($filters['ids'])) {
            if (empty($filters['ids'])) {
                $Query->and_where('1=0'); // TODO: this is a new low.. find another way to cancel query
            } else {
                $Query->aw_numeric_in('id', $filters['ids'], $Query->table_label());
            }
        }

        if (isset($options['order_by'])) { // TODO commenting required about the array situation
            $order_by = $options['order_by'];

            if (is_string($order_by)) {
                $Query->order_by($order_by);
            } elseif (is_array($order_by)) {
                foreach ($options['order_by'] as $order_by) {
                    if (!isset($order_by[2])) {
                        array_unshift($order_by, '');
                    }

                    list($order_table, $order_field, $order_direction) = $order_by;
                    $Query->order_by([$order_table ?? '', $order_field, $order_direction]);
                }
            }
        }

        if (isset($options['limit']) && is_array($options['limit'])) {
            $Query->limit($options['limit'][1], $options['limit'][0]);
        }

        return $Query;
    }
}

<?php

namespace HexMakina\TightORM;

use HexMakina\BlackBox\ORM\ModelInterface;

trait HasOne
{

    public static function extract(ModelInterface $extract_model, ModelInterface $from_model, $ignore_nullable = false): ?ModelInterface
    {
        $extraction_class = get_class($extract_model);
        $extraction_table = $extraction_class::table();

        foreach ($extraction_table->columns() as $column_name => $column) {
            $probe_name = $extraction_class::tableAlias() . '_' . $column_name;

            if (!is_null($probe_res = $from_model->get($probe_name))) {
                $extract_model->set($column_name, $probe_res);
            } elseif (!$column->isNullable() && $ignore_nullable === false) {
                return null;
            }
        }

        return $extract_model;
    }
}

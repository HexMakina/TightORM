<?php

namespace HexMakina\Crudites;

use \HexMakina\Crudites\Interfaces\TableManipulationInterface;

abstract class TableToModel extends Crudites
{
    //check all primary keys are set (TODO that doesn't work unles AIPK.. nice try)
    public function is_new() : bool
    {
        $match = static::table()->primary_keys_match(get_object_vars($this));
        return empty($match);
    }

    public function get_id($mode = null)
    {
        $primary_key = static::table()->auto_incremented_primary_key();
        if (is_null($primary_key) && count($pks = static::table()->primary_keys())==1) {
            $primary_key = current($pks);
        }

        return $mode === 'name' ? $primary_key->name() : $this->get($primary_key->name());
    }

    public function get($prop_name)
    {
        if (property_exists($this, $prop_name) === true) {
            return $this->$prop_name;
        }

        return null;
    }

    public function set($prop_name, $value)
    {
        $this->$prop_name = $value;
    }

    public function import($assoc_data)
    {
        if (!is_array($assoc_data)) {
            throw new \Exception(__FUNCTION__.'(assoc_data) parm is not an array');
        }

        // shove it all up in model, god will sort them out
        foreach ($assoc_data as $field => $value) {
            $this->set($field, $value);
        }

        return $this;
    }

    public static function table() : TableManipulationInterface
    {
        $table = static::table_name();
        $table = self::inspect($table);

        return $table;
    }

    public static function table_name() : string
    {
        $reflect = new \ReflectionClass(get_called_class());

        $table_name = $reflect->getConstant('TABLE_NAME');

        if ($table_name === false) {
            $calling_class = $reflect->getShortName();
            if (defined($const_name = 'TABLE_'.strtoupper($calling_class))) {
                $table_name = constant($const_name);
            } else {
                $table_name = strtolower($calling_class);
            }
        }

        return $table_name;
    }


    public function to_table_row($operator_id = null)
    {
        if (!is_null($operator_id) && $this->is_new() && is_null($this->get('created_by'))) {
            $this->set('created_by', $operator_id);
        }

        $model_data = get_object_vars($this);

        // 1. Produce OR restore a row
        if ($this->is_new()) {
            $table_row = static::table()->produce($model_data);
        } else {
            $table_row = static::table()->restore($model_data);
        }

        // 2. Apply alterations from form_model data
        $table_row->alter($model_data);

        return $table_row;
    }
}

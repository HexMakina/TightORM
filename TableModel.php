<?php

namespace HexMakina\TightORM;

use \HexMakina\Crudites\Crudites;
use \HexMakina\Crudites\CruditesException;
use \HexMakina\Interfaces\Database\TableManipulationInterface;
use \HexMakina\Interfaces\Database\SelectInterface;

abstract class TableModel extends Crudites
{

    //check all primary keys are set (FIXME that doesn't work unles AIPK.. nice try)
    public function is_new(): bool
    {
        $match = static::table()->primary_keys_match(get_object_vars($this));
        return empty($match);
    }

    public function get_id($mode = null)
    {
        $primary_key = static::table()->auto_incremented_primary_key();
        if (is_null($primary_key) && count($pks = static::table()->primary_keys()) == 1) {
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
            throw new \Exception(__FUNCTION__ . '(assoc_data) parm is not an array');
        }

        // shove it all up in model, god will sort them out
        foreach ($assoc_data as $field => $value) {
            $this->set($field, $value);
        }

        return $this;
    }

    public static function table(): TableManipulationInterface
    {
        $table = static::table_name();
        $table = self::inspect($table);

        return $table;
    }

    public static function table_name(): string
    {
        $reflect = new \ReflectionClass(get_called_class());

        $table_name = $reflect->getConstant('TABLE_NAME');

        if ($table_name === false) {
            $calling_class = $reflect->getShortName();
            if (defined($const_name = 'TABLE_' . strtoupper($calling_class))) {
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

    //------------------------------------------------------------  Data Retrieval
    // DEPRECATED, only exist for unit testing vis-a-vis TightModelSelector
    public static function query_retrieve($filters = [], $options = []): SelectInterface
    {
        $class = get_called_class();
        $query = (new TableModelSelector(new $class()))->select($filters, $options);
        return $query;
    }

    // success: return PK-indexed array of results (associative array or object)
    public static function retrieve(SelectInterface $Query): array
    {
        $ret = [];
        $pk_name = implode('_', array_keys($Query->table()->primary_keys()));

        if (count($pks = $Query->table()->primary_keys()) > 1) {
            $concat_pk = sprintf('CONCAT(%s) as %s', implode(',', $pks), $pk_name);
            $Query->select_also([$concat_pk]);
        }

        try {
            $Query->run();
        } catch (CruditesException $e) {
            return [];
        }

        if ($Query->is_success()) {
            foreach ($Query->ret_obj(get_called_class()) as $rec) {
                  $ret[$rec->get($pk_name)] = $rec;
            }
        }

        return $ret;
    }

    /* USAGE
    * one($primary_key_value)
    * one($unique_column, $value)
    */
    public static function one($arg1, $arg2 = null)
    {
        $mixed_info = is_null($arg2) ? $arg1 : [$arg1 => $arg2];

        $unique_identifiers = get_called_class()::table()->match_uniqueness($mixed_info);

        if (empty($unique_identifiers)) {
            throw new CruditesException('UNIQUE_IDENTIFIER_NOT_FOUND');
        }

        $Query = static::query_retrieve([], ['eager' => true])->aw_fields_eq($unique_identifiers);
        switch (count($res = static::retrieve($Query))) {
            case 0:
                throw new CruditesException('INSTANCE_NOT_FOUND');
            case 1:
                return current($res);
            default:
                throw new CruditesException('SINGULAR_INSTANCE_ERROR');
        }
    }

    public static function exists($arg1, $arg2 = null)
    {
        try {
            return self::one($arg1, $arg2);
        } catch (CruditesException $e) {
            return null;
        }
    }


    public static function any($field_exact_values, $options = [])
    {
        $Query = static::query_retrieve([], $options)->aw_fields_eq($field_exact_values);
        return static::retrieve($Query);
    }

    public static function filter($filters = [], $options = []): array
    {
        return static::retrieve(static::query_retrieve($filters, $options));
    }

    public static function listing($filters = [], $options = []): array
    {
        return static::retrieve(static::query_retrieve($filters, $options)); // listing as arrays for templates
    }



    public static function get_many_by_AIPK($aipk_values)
    {
        if (!empty($aipk_values) && !is_null($AIPK = static::table()->auto_incremented_primary_key())) {
            return static::retrieve(static::table()->select()->aw_numeric_in($AIPK, $aipk_values));
        }

        return null;
    }
}

<?php

namespace HexMakina\TightORM;

use HexMakina\BlackBox\Database\SchemaInterface;
use HexMakina\Crudites\Crudites;
use HexMakina\Crudites\CruditesException;
use HexMakina\Crudites\Row;
use HexMakina\BlackBox\Database\SelectInterface;

abstract class TableModel extends Crudites
{

    private SchemaInterface $schema;
    private string $table;

    
    //check all primary keys are set (FIXME that doesn't work unles AIPK.. nice try)
    public function isNew(): bool
    {
        $match = static::table()->primaryKeysMatch(get_object_vars($this));
        return empty($match);
    }

    public function getId($mode = null)
    {
        $primary_key = $this->schema->autoIncrementedPrimaryKey(static::table());
        if (is_null($primary_key) && count($pks = static::table()->primaryKeys()) == 1) {
            $primary_key = current($pks);
        }

        return $mode === 'name' ? $primary_key->name() : $this->get($primary_key->name());
    }

    public function get($prop_name)
    {
        if (property_exists($this, $prop_name)) {
            return $this->$prop_name;
        }

        return null;
    }

    public function set($prop_name, $value): void
    {
        $this->$prop_name = $value;
    }

    public function import($assoc_data): self
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


    // relational mapping
    public static function table(): string
    {
        $reflectionClass = new \ReflectionClass(get_called_class());

        $table_name = $reflectionClass->getConstant('TABLE_NAME');

        if ($table_name === false) {
            $shortName = $reflectionClass->getShortName();
            $table_name = defined($const_name = 'TABLE_' . strtoupper($shortName)) ? constant($const_name) : strtolower($shortName);
        }

        return $table_name;
    }

    public function to_table_row($operator_id = null)
    {
        if (!is_null($operator_id) && $this->isNew() && is_null($this->get('created_by'))) {
            $this->set('created_by', $operator_id);
        }

        $model_data = get_object_vars($this);

        if($this->isNew()){
            // $table_row = new Row($this, $model_data);
            $table_row = new Row($this->schema, static::table(), $model_data);
        }
        else{
            $table_row = new Row($this->schema);
            $table_row->load($model_data);
        }
        // 1. Produce OR restore a row

        // 2. Apply alterations from form_model data
        $table_row->alter($model_data);

        return $table_row;
    }

    //------------------------------------------------------------  Data Retrieval
    // DEPRECATED, only exist for unit testing vis-a-vis TightModelSelector
    public static function query_retrieve($filters = [], $options = []): SelectInterface
    {
        $class = get_called_class();
        return (new TableModelSelector(new $class()))->select($filters, $options);
    }

    // success: return PK-indexed array of results (associative array or object)
    public static function retrieve(SelectInterface $select): array
    {
        $ret = [];
        $pk_name = implode('_', array_keys($select->table()->primaryKeys()));

        if (count($pks = $select->table()->primaryKeys()) > 1) {
            $concat_pk = sprintf('CONCAT(%s) as %s', implode(',', $pks), $pk_name);
            $select->selectAlso([$concat_pk]);
        }

        try {
            $select->run();
        } catch (CruditesException $e) {
            return [];
        }

        if ($select->isSuccess()) {
            foreach ($select->retObj(get_called_class()) as $rec) {
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
        $datass = [];

        if (!is_null($arg2)) {
            $datass = [$arg1 => $arg2];
        } elseif (!is_array($arg1) && count(get_called_class()::table()->primaryKeys()) === 1) {
            $datass = [current(get_called_class()::table()->primaryKeys())->name() => $arg1];
        } elseif (is_array($arg1)) {
            $datass = $arg1;
        } else {
            throw new CruditesException('ARGUMENTS_ARE_NOT_ACTIONNABLE');
        }

        $unique_identifiers = get_called_class()::table()->matchUniqueness($datass);

        if (empty($unique_identifiers)) {
            throw new CruditesException('UNIQUE_IDENTIFIER_NOT_FOUND');
        }

        $select = static::query_retrieve([], ['eager' => true])->whereFieldsEQ($unique_identifiers);
        $res = static::retrieve($select);

        switch (count($res)) {
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
        } catch (CruditesException $cruditesException) {
            return null;
        }
    }


    /**
     * @return mixed[]
     */
    public static function any($field_exact_values, $options = []): array
    {
        $select = static::query_retrieve([], $options)->whereFieldsEQ($field_exact_values);
        return static::retrieve($select);
    }

    /**
     * @return mixed[]
     */
    public static function filter($filters = [], $options = []): array
    {
        return static::retrieve(static::query_retrieve($filters, $options));
    }

    /**
     * @return mixed[]
     */
    public static function listing($filters = [], $options = []): array
    {
        return static::retrieve(static::query_retrieve($filters, $options)); // listing as arrays for templates
    }



    public static function get_many_by_AIPK($aipk_values): ?array
    {
        if (empty($aipk_values)) {
            return null;
        }
        if (is_null($AIPK = static::table()->autoIncrementedPrimaryKey())) {
            return null;
        }
        return static::retrieve(static::table()->select()->whereNumericIn($AIPK, $aipk_values));
    }
}

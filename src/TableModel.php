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

    public function pk()
    {
        $primary_key = static::table()->autoIncrementedPrimaryKey();
        if (is_null($primary_key) && count($pks = static::table()->primaryKeys()) == 1) {
            $primary_key = current($pks);
        }

        return $this->get($primary_key->name());
    }

    public function id()
    {
        return $this->get('id');
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
        $called_class = new \ReflectionClass(get_called_class());

        $table_name = $reflectionClass->getConstant('TABLE_NAME');

        if ($table_name === false) {
            $shortName = $reflectionClass->getShortName();
            $table_name = defined($const_name = 'TABLE_' . strtoupper($shortName)) ? constant($const_name) : strtolower($shortName);
        }

        return $table_name;
        $table_name = $called_class->getConstant('TABLE_NAME');
        if($table_name !== false)
            return $table_name;

        $class_name = $called_class->getShortName();
        
        if (defined($const_name = 'TABLE_' . strtoupper($class_name)))
            return constant($const_name);
        
        return strtolower($class_name);
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


    private static function actionnableParams($arg1, $arg2 = null): array
    {
        $unique_identifiers = null;
        
        $table = get_called_class()::table();

        // case 3
        if(is_array($arg1) && !empty($arg1))
        {
            $unique_identifiers = $arg1;
        }

        // case 2
        else if (is_string($arg1) && is_scalar($arg2))
        {   
            $unique_identifiers = [$arg1 => $arg2];
        }

        // case 1
        else if (is_scalar($arg1) && count($table->primaryKeys()) === 1)
        {   
            $pk = current($table->primaryKeys())->name();
            $unique_identifiers = [$pk => $arg1];
        } 
        else
            throw new CruditesException('ARGUMENTS_ARE_NOT_ACTIONNABLE');


        // Find the unique identifier(s) in the database.
        $unique_identifiers = $table->matchUniqueness($unique_identifiers);
        if (empty($unique_identifiers)) {
            throw new CruditesException('UNIQUE_IDENTIFIER_NOT_FOUND');
        }

        return $unique_identifiers;
    }


    /**
     * Retrieve a single instance of the model by its unique identifier(s).
     * Throws CruditesException if the unique identifier yields no or multiple instances.
     *
     * @param mixed $arg1 The value of the primary key or an array of column-value pairs.
     * @param mixed|null $arg2 The value of the primary key if $arg1 is a string, otherwise null.
     * 
     * @return mixed The retrieved instance of the model.
     * 
     * @throws CruditesException If the arguments are not actionable, the unique identifier is not found, or multiple instances are found.
     * 
     * USAGE
     *  Case 1:  Class::one($primary_key_value)
     *  Case 2:  Class::one($unique_column, $value)
     *  Case 3:  Class::one([$unique_column => $value, $unique_column2 => $value2])
     * 
     */
    public static function one($arg1, $arg2 = null): self
    {
        $unique_identifiers = static::actionnableParams($arg1, $arg2);
        // vd($arg1, 'arg1');
        // vd($arg2, 'arg2');
        // vd($unique_identifiers, static::class);

        $records = static::any($unique_identifiers);
        switch (count($records)) {
            case 0:
                throw new CruditesException('NO_INSTANCE_MATCH_UNIQUE_IDENTIFIERS');

            case 1:
                return current($records);

            default:
                throw new CruditesException('MULTIPLE_INSTANCES_MATCH_UNIQUE_IDENTIFIERS');
        }
    }

    /**
     * Attempts to retrieve a single instance of the model by its unique identifier(s).
     * If no instance is found, returns null.
     */

    public static function exists($arg1, $arg2 = null): ?self
    {
        try {
            return self::one($arg1, $arg2);
        } 
        catch (CruditesException $e) {
            return null;
        }
    }

    public static function any($field_exact_values=[], $options = [])
    {
        $select = static::filter($field_exact_values, $options);
        return static::retrieve($select);
    }

    
    public static function filter($filters = [], $options = []): SelectInterface
    {
        $query = (new TableModelSelector(get_called_class()))->select($filters, $options);
        return $query;

        // $query = static::query_retrieve($filters, $options);
        // return static::retrieve($query);
    }

    public static function count($filters = [], $options = []): int
    {
        $query = static::filter($filters, ['eager' => false]);
        $query->columns(['COUNT(*) as counter']);
        $res = static::retrieve($query);
        $res = array_pop($res);
        return (int)$res->counter;
    }

    public static function listing($filters = [], $options = []): array
    {
        return static::retrieve(static::filter($filters, $options)); // listing as arrays for templates
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

<?php

namespace HexMakina\TightORM;

use HexMakina\Crudites\{CruditesException};
use HexMakina\Crudites\Interfaces\TableManipulationInterface;
use HexMakina\Crudites\Interfaces\SelectInterface;
use HexMakina\TightORM\Interfaces\ModelInterface;
use HexMakina\Traitor\Traitor;

abstract class TightModel extends TableModel implements ModelInterface
{
    use Traitor;

    public function __toString()
    {
        return static::class_short_name() . ' #' . $this->get_id();
    }

    // public function traceable(): bool
    // {
    //     return true;
    // }
    //
    // public function traces(): array
    // {
    //     return [];
    // }

    public function immortal(): bool
    {
        return self::IMMORTAL_BY_DEFAULT;
    }

    public function extract(ModelInterface $extract_model, $ignore_nullable = false)
    {
        $extraction_class = get_class($extract_model);

        $extraction_table = $extraction_class::table();
        foreach ($extraction_table->columns() as $column_name => $column) {
            $probe_name = $extraction_class::table_alias() . '_' . $column_name;

            if (!is_null($probe_res = $this->get($probe_name))) {
                $extract_model->set($column_name, $probe_res);
            } elseif (!$column->is_nullable() && $ignore_nullable === false) {
                return null;
            }
        }

        return $extract_model;
    }

    public function copy()
    {
        $class = get_called_class();
        $clone = new $class();

        foreach ($class::table()->columns() as $column_name => $column) {
            if (!is_null($column->default())) {
                continue;
            }
            if ($column->is_auto_incremented()) {
                continue;
            }

            $clone->set($column_name, $this->get($column_name));
        }

        unset($clone->created_by);
        return $clone;
    }

    public function toggle($prop_name)
    {
        parent::toggle_boolean(static::table(), $prop_name, $this->get_id());
    }


    public function validate(): array
    {
        return []; // no errors
    }

    public function before_save(): array
    {
        return [];
    }

    public function after_save()
    {
        return true;
    }

    // return array of errors
    public function save($operator_id, TracerInterface $tracer = null)
    {
        try {
            if (!empty($errors = $this->search_and_execute_trait_methods('before_save'))) {
                return $errors;
            }

            if (!empty($errors = $this->before_save())) {
                return $errors;
            }

            if (!empty($errors = $this->validate())) { // Model level validation
                return $errors;
            }

            //1 tight model *always* match a single table row
            $table_row = $this->to_table_row($operator_id);


            if ($table_row->is_altered()) { // someting to save ?
                if (!empty($persistence_errors = $table_row->persist())) { // validate and persist
                    $errors = [];
                    foreach ($persistence_errors as $column_name => $err) {
                        $errors[sprintf('MODEL_%s_FIELD_%s', static::model_type(), $column_name)] = $err;
                    }

                    return $errors;
                }

                if (!is_null($tracer) && $this->traceable()) {
                    $tracer->trace($table_row->last_alter_query(), $operator_id, $this->get_id());
                }

                // reload row
                $refreshed_row = static::table()->restore($table_row->export());

                // update model
                $this->import($refreshed_row->export());
            }

            $this->search_and_execute_trait_methods('after_save');
            $this->after_save();
        } catch (\Exception $e) {
            return [$e->getMessage()];
        }

        return [];
    }

    // returns false on failure or last executed delete query
    public function before_destroy(): bool
    {
        if ($this->is_new() || $this->immortal()) {
            return false;
        }

        $this->search_and_execute_trait_methods(__FUNCTION__);

        return true;
    }

    public function after_destroy()
    {
        $this->search_and_execute_trait_methods(__FUNCTION__);
    }

    public function destroy($operator_id, $tracer = null): bool
    {
        if ($this->before_destroy() === false) {
            return false;
        }

        $table_row = static::table()->restore(get_object_vars($this));

        if ($table_row->wipe() === false) {
            return false;
        }

        if (!is_null($tracer) && $this->traceable()) {
            $tracer->trace($table_row->last_query(), $operator_id, $this->get_id());
        }

        $this->after_destroy();

        return true;
    }

    //------------------------------------------------------------  Data Retrieval
    public static function query_retrieve($filters = [], $options = []): SelectInterface
    {
        $class = get_called_class();
        $query = (new TightModelSelector(new $class()))->select($filters, $options);
        // $query_old = self::query_retrieve_old($filters,$options);
        //
        // if($res = $query->compare($query_old) !== true)
        // {
        //   vd($res);
        //   vd($query->statement(), 'new statement');
        //   vd($query_old->statement(), 'old statement');
        //   ddt('different');
        // }
        return $query;
    }

    public static function exists($arg1, $arg2 = null)
    {
        try {
            return self::one($arg1, $arg2);
        } catch (CruditesException $e) {
            return null;
        }
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

    public static function get_many_by_AIPK($aipk_values)
    {
        if (!empty($aipk_values) && !is_null($AIPK = static::table()->auto_incremented_primary_key())) {
            return static::retrieve(static::table()->select()->aw_numeric_in($AIPK, $aipk_values));
        }

        return null;
    }


    //------------------------------------------------------------  Introspection & Data Validation
    /**
     * Cascade of table name guessing goes:
     * 1. Constant 'TABLE_ALIAS' defined in class
     * 2. lower-case class name
     *
     * @throws CruditesException, if ever called from Crudites class, must be inherited call
     */
    public static function table_alias(): string
    {
        return defined(get_called_class() . '::TABLE_ALIAS') ? static::TABLE_ALIAS : static::model_type();
    }

    public static function class_short_name()
    {
        return (new \ReflectionClass(get_called_class()))->getShortName();
    }

    public static function model_type(): string
    {
        return strtolower(self::class_short_name());
    }

    public static function select_also()
    {
        return ['*'];
    }
}

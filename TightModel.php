<?php

namespace HexMakina\TightORM;

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
    public function save($operator_id)
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

    public function destroy($operator_id): bool
    {
        if ($this->before_destroy() === false) {
            return false;
        }

        $table_row = static::table()->restore(get_object_vars($this));

        if ($table_row->wipe() === false) {
            return false;
        }

        $this->after_destroy();

        return true;
    }

    //------------------------------------------------------------  Data Retrieval
    public static function query_retrieve($filters = [], $options = []): SelectInterface
    {
        $class = get_called_class();
        $query = (new TightModelSelector(new $class()))->select($filters, $options);
        return $query;
    }

    //------------------------------------------------------------  Introspection & Data Validation
    /**
     * Cascade of table name guessing goes:
     * 1. Constant 'TABLE_ALIAS' defined in class
     * 2. lower-case class name
     *
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

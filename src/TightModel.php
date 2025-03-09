<?php
namespace HexMakina\TightORM;

use HexMakina\BlackBox\Database\SelectInterface;
use HexMakina\BlackBox\ORM\ModelInterface;
use HexMakina\Traitor\Traitor;

abstract class TightModel extends TableModel implements ModelInterface
{
    use Traitor;
    
    public function __toString()
    {
        return static::class_short_name() . ' #' . $this->id();
    }

    public function nid(): string
    {
        return static::class_short_name();
    }

    public function urn(): string
    {
        return $this->nid() . ':' . $this->id();
    }
    
    public function immortal(): bool
    {
        return self::IMMORTAL_BY_DEFAULT;
    }

    // creates new instance, copy data from $this
    // does not copy fields with default and AI field
    public function copy()
    {
        $class = get_called_class();
        $clone = new $class();

        foreach ($class::table()->columns() as $column_name => $column) {
            if (!is_null($column->default())) {
                continue;
            }
            if ($column->isAutoIncremented()) {
                continue;
            }

            $clone->set($column_name, $this->get($column_name));
        }

        // TODO: assuming created_by, must be a function that returns the creation tracking field, make an interface for heaven's sake
        unset($clone->created_by);
        return $clone;
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

    // return array of errors on failure
    // updates the model on success
    public function save($operator_id)
    {
        try {
            // errors detection befire saving
            $errors = [];
            if (empty($errors = $this->traitor('before_save'))) {
                if (empty($errors = $this->before_save())) {
                    $errors = $this->validate();
                }
            }
            if (!empty($errors)) {
                return $errors;
            }

            
            // a tight model *always* match a single table row
            $table_row = $this->to_table_row($operator_id);
            
            if ($table_row->isAltered()) { // someting to save ?
                
                $errors = $table_row->persist();

                if (!empty($errors)) {
                    return $errors;
                }
                
                $refreshed_row = static::table()->restore($table_row->export());
                $this->import($refreshed_row->export());
            }

            $this->traitor('after_save');
            $this->after_save();
        } catch (\Exception $e) {
            return [$e->getMessage()];
        }

        return [];
    }

    // returns false on failure or last executed delete query
    public function before_destroy(): bool
    {
        if ($this->isNew() || $this->immortal()) {
            return false;
        }

        $this->traitor(__FUNCTION__);

        return true;
    }

    public function after_destroy()
    {
        $this->traitor(__FUNCTION__);
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
    public static function filter($filters = [], $options = []): SelectInterface
    {
        $class = static::class;
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
    public static function tableAlias(): string
    {
        if (defined(get_called_class() . '::TABLE_ALIAS')) {
            return get_called_class()::TABLE_ALIAS;
        }

        return static::model_type();
    }

    public static function model_type(): string
    {
        if (defined(get_called_class() . '::MODEL_TYPE')) {
            return get_called_class()::MODEL_TYPE;
        }

        return strtolower(self::class_short_name());
    }

    
    public static function class_short_name(): string
    {
        return (new \ReflectionClass(get_called_class()))->getShortName();
    }


    public static function selectAlso()
    {
        return ['*'];
    }
}

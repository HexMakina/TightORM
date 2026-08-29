<?php

namespace HexMakina\TightORM;

use HexMakina\Crudites\Crudites;
use HexMakina\Crudites\Table\Row;
use HexMakina\Crudites\CruditesException;
use HexMakina\BlackBox\Database\TableInterface;
use HexMakina\BlackBox\Database\SelectInterface;

abstract class Model extends Row
{
    // returns the value of the PK, whatever it's name
    // returns the name of the PK, if $mode === 'name'
    public function id($mode = null)
    {
        $primary_key = $this->table()->autoIncrementedPrimaryKey();

        if (is_null($primary_key) && count($pks = $this->table()->primaryKeys()) == 1) {
            $primary_key = current($pks);
        }

        return $mode === 'name' ? $primary_key->name() : $this->get($primary_key->name());
    }

    // Model might have properties, if not, use row data
    public function get($prop_name)
    {
        if (property_exists($this, $prop_name) === true) {
            return $this->$prop_name;
        }

        return parent::get($prop_name);
    }

    // Model might have properties, if not, use row data
    public function set($prop_name, $value): void
    {
        if (property_exists($this, $prop_name) === true) {
            $this->$prop_name = $value;
        } else {
            parent::set($prop_name, $value);
        }
    }
}

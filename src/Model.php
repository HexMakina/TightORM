<?php

namespace HexMakina\TightORM;

use HexMakina\Crudites\Crudites;
use HexMakina\Crudites\Table\Row;
use HexMakina\Crudites\CruditesException;
use HexMakina\BlackBox\Database\TableInterface;
use HexMakina\BlackBox\Database\SelectInterface;

abstract class Model extends Row
{
    public function id($mode = null)
    {
        $primary_key = $this->table()->autoIncrementedPrimaryKey();

        if (is_null($primary_key) && count($pks = $this->table()->primaryKeys()) == 1) {
            $primary_key = current($pks);
        }

        return $mode === 'name' ? $primary_key->name() : $this->get($primary_key->name());
    }

    public function get($prop_name)
    {
        if (property_exists($this, $prop_name) === true) {
            return $this->$prop_name;
        }

        return parent::get($prop_name);
    }

    public function set($prop_name, $value)
    {
        if (property_exists($this, $prop_name) === true) {
        {
            $this->$prop_name = $value;
        }
        else parent::set($prop_name, $value);
    }
}

<?php

namespace HexMakina\TightORM;

use HexMakina\BlackBox\ORM\ModelInterface;
use HexMakina\BlackBox\Database\SelectInterface;

class TableModelSelector
{
    private $class;
    private $table;
    private $query;

    public function __construct(string $class)
    {
        if(class_exists($class) === false)
            throw new \Exception('CLASS_NOT_FOUND');
        
        $this->class = $class;
        $this->table = $this->class::table();
    }

    public function select($filters = [], $options = []): SelectInterface
    {
        $this->query = $this->table->select(null, $options['table_alias'] ?? $this->class::tableAlias());
    
        // if the array of filters contains an index macthing a column name, it is used as a EQ filter
        // it is used as a where equal clause
        foreach (array_keys($this->table->columns()) as $column_name) {
            if (isset($filters[$column_name]) && is_scalar($filters[$column_name])) {
                
                $this->query->whereEQ($column_name, $filters[$column_name]);
                
                unset($filters[$column_name]);
            }
        }
        
        // now that the possibility of index matching columns is removed, 
        // the rest of the array is used as a filter shortcuts

        // shortcut 'ids' => [1,2,3] is used as a where in clause
        if (isset($filters['ids'])) {
            $this->filterByIds($filters['ids']);
        }

        // shortcut 'content'
        if (isset($filters['content'])) {
            $this->query->whereFilterContent($filters['content']);
        }


        // processing options 
        if (isset($options['order_by'])) {
            $this->query->orderBy($options['order_by']);
        }

        if (isset($options['limit'])) {
            // if limit is an array, it is used as [$limit, $offset]
            // else, it is used as [$limit, 0]
            [$limit, $offset] = is_array($options['limit']) ? $options['limit'] : [$options['limit'], 0];

            $this->query->limit($limit, $offset);
        }


        return $this->query;
    }

    private function filterByIds(array $ids)
    {
        
        if (empty($ids)) {
            // as a security, if array is empty, 
            // the query is cancelled by setting the limit to 0
            $this->query->limit(0); 
        } else {
            $this->query->whereNumericIn('id', $ids, $this->query->tableLabel());
        }
    }
}

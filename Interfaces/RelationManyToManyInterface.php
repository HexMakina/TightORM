<?php

namespace HexMakina\TightORM\Interfaces;

interface RelationManyToManyInterface
{
    public static function set_many($linked_models, ModelInterface $m);
    public static function set_many_by_ids($linked_ids, ModelInterface $m);

    public static function otm($k = null);
    public static function model_type(): string;
}

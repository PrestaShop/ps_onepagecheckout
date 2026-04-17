<?php

namespace Tests\PHP\Mocks;

class LegacyEntityMapper
{
    public function load($id, $id_lang, $entity, $entity_defs, $id_shop, $should_cache_objects): void
    {
        $entity->id = (int) $id;
    }
}

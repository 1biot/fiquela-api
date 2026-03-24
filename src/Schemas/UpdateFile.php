<?php

namespace Api\Schemas;

use Nette\Schema\Elements\Structure;
use Nette\Schema\Elements\Type;
use Nette\Schema\Expect;

class UpdateFile implements Schema
{
    public function getSchema(): Structure|Type
    {
        return Expect::array([
            'params' => Expect::arrayOf(Expect::string()->nullable())->nullable(),
            'query' => Expect::string()->nullable(),
        ]);
    }
}

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
            'encoding' => Expect::string()->nullable(),
            'delimiter' => Expect::string()->nullable(),
            'query' => Expect::string()->nullable(),
        ]);
    }
}

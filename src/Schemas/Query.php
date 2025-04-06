<?php

namespace Api\Schemas;

use Api;
use Nette\Schema\Elements\Structure;
use Nette\Schema\Elements\Type;
use Nette\Schema\Expect;

class Query implements Schema
{
    public function getSchema(): Structure|Type
    {
        return Expect::array([
            'query' => Expect::string()->required(),
            'file' => Expect::string()->nullable(),
            'limit' => Expect::int()->min(1)->max(Api\Endpoints\Query::DefaultLimit)->nullable(),
            'page' => Expect::int()->min(1)->nullable(),
        ]);
    }
}

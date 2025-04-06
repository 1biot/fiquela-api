<?php

namespace Api\Schemas;

use Nette\Schema\Elements\Structure;
use Nette\Schema\Elements\Type;
use Nette\Schema\Expect;

class DownloadFile implements Schema
{
    public function getSchema(): Structure|Type
    {
        return Expect::array(
            [
                'url' => Expect::string()->required(),
                'name' => Expect::string()->nullable(),
                'type' => Expect::string()->nullable(),
                'encoding' => Expect::string()->nullable(),
                'delimiter' => Expect::string()->nullable(),
                'query' => Expect::string()->nullable()
            ]
        );
    }
}

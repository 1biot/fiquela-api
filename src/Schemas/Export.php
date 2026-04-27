<?php

namespace Api\Schemas;

use Nette\Schema\Elements\Structure;
use Nette\Schema\Elements\Type;
use Nette\Schema\Expect;

class Export implements Schema
{
    public const array Formats = ['json', 'ndjson', 'csv', 'xml', 'xlsx', 'ods'];

    /**
     * Schema validates query-string params for GET /api/v1/export/{hash}.
     * All values arrive as strings (URL query); FQL Format::validateParams() runs the
     * final per-writer checks (single-char delimiter/enclosure, valid encoding, ...).
     */
    public function getSchema(): Structure|Type
    {
        return Expect::array([
            'format' => Expect::anyOf(...self::Formats)->default('json'),
            // CSV writer params (FQL\Stream\Writers\CsvWriter)
            'delimiter' => Expect::string()->nullable(),
            'enclosure' => Expect::string()->nullable(),
            'useHeader' => Expect::anyOf('0', '1')->nullable(),
            'bom' => Expect::anyOf('0', '1')->nullable(),
            // CSV + XML writer param
            'encoding' => Expect::string()->nullable(),
            // Response handling
            'force' => Expect::anyOf('0', '1', 'true', 'false', true, false)->nullable(),
        ]);
    }
}

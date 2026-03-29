<?php

namespace Api;

use FQL\Interface\Query;
use FQL\Query\FileQuery;

final readonly class QueryResult
{
    public function __construct(
        public Query $query,
        public string $hash,
        public FileQuery $originalFileQuery,
        public bool $workspaceChanged = false,
        public ?array $intoSchema = null,
    ) {
    }
}

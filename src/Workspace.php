<?php

namespace Api;

use Api\Utils\Downloader;
use FQL\Enum\Format;
use FQL\Enum\Type;
use FQL\Exception\FileNotFoundException;
use FQL\Exception\InvalidFormatException;
use FQL\Interface;
use FQL\Results\Stream as StreamResults;
use FQL\Query;
use FQL\Sql;
use FQL\Stream;
use Slim\Psr7\UploadedFile;
use Symfony\Component\Uid\Uuid;

class Workspace
{
    private const string CachePath = 'cache';
    private const string FilesPath = 'files';
    private const string HistoryPath = 'history';
    private const string SchemasPath = 'schemas';

    private readonly string $rootPath;

    public function __construct(string $rootPath)
    {
        $this->rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);
        if (is_writable($this->rootPath) === false) {
            throw new \RuntimeException('Root path is not writable');
        }

        $this->ensureDirectory($this->getCachePath());
        $this->ensureDirectory($this->getFilesPath());
        $this->ensureDirectory($this->getHistoryPath());
        $this->ensureDirectory($this->getSchemasPath());
    }

    public function getCachePath(): string
    {
        return $this->getRootPath() . DIRECTORY_SEPARATOR . self::CachePath;
    }

    public function getFilesPath(): string
    {
        return $this->getRootPath() . DIRECTORY_SEPARATOR . self::FilesPath;
    }

    public function getHistoryPath(): string
    {
        return $this->getRootPath() . DIRECTORY_SEPARATOR . self::HistoryPath;
    }

    public function getSchemasPath(): string
    {
        return $this->getRootPath() . DIRECTORY_SEPARATOR . self::SchemasPath;
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    /**
     * @return array{0: Interface\Query, 1: string}
     * @throws InvalidFormatException
     * @throws FileNotFoundException
     * @throws \Exception
     */
    public function runQuery(string $query, ?string $fileName = null): array
    {
        if ($fileName !== null) {
            $stream = Stream\Provider::fromFile($this->getFilesPath() . DIRECTORY_SEPARATOR . $fileName);
        }

        $sqlQuery = new Sql\Sql(trim($query));
        $sqlQuery->setBasePath($this->getFilesPath());
        $queryObject = isset($stream) ? $sqlQuery->parseWithQuery($stream->query()) : $sqlQuery->toQuery();
        if (is_writable($this->getCachePath())) {
            if (!$this->queryResultExists($queryObject)) {
                $this->saveQueryResult($queryObject);
            }
        }

        $originalQuery = $queryObject;
        if ($this->queryResultExists($queryObject)) {
            $queryObject = Stream\JsonStream::open($this->getQueryCacheFile($queryObject))->query();
        }

        $this->logQuery($query, $originalQuery);
        return [$queryObject, md5((string) $originalQuery)];
    }

    public function addFileFromUploadedFile(UploadedFile $uploadedFile): array
    {
        $format = $this->getFileTypeFromUploadedFile($uploadedFile, [\FQL\Enum\Format::class, 'fromExtension']);
        $schema = $this->createSchemaFromUploadedFile($uploadedFile, $format);
        $moveToPath = $this->getFilesPath() . DIRECTORY_SEPARATOR . $schema['name'];

        $uploadedFile->moveTo($moveToPath);
        chmod($moveToPath, 0644);
        $this->saveSchema($schema);
        return $schema;
    }

    private function createSchemaFromUploadedFile(UploadedFile $uploadedFile, Format $format): array
    {
        return [
            'uuid' => Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), $uploadedFile->getClientFilename())->toRfc4122(),
            'originalName' => $uploadedFile->getClientFilename(),
            'name' => $this->normalizeFilename($uploadedFile->getClientFilename()),
            'encoding' => null,
            'type' => $format->value,
            'size' => $uploadedFile->getSize(),
            'delimiter' => null,
            'query' => null,
            'count' => 0,
            'columns' => [],
        ];
    }

    private function createSchemaFromDownloadedFile(\SplFileInfo $file, Format $format): array
    {
        return [
            'uuid' => Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), $file->getFilename())->toRfc4122(),
            'originalName' => $file->getFilename(),
            'name' => $this->normalizeFilename($file->getFilename()),
            'encoding' => null,
            'type' => $format->value,
            'size' => $file->getSize(),
            'delimiter' => null,
            'query' => null,
            'count' => 0,
            'columns' => [],
        ];
    }

    /**
     * @param array $schema
     */
    public function saveSchema(array &$schema): void
    {
        $fileName = $this->getSchemasPath() . DIRECTORY_SEPARATOR . sprintf('%s.json', $schema['name']);
        $this->extendsSchema($schema);
        file_put_contents($fileName, json_encode($schema, JSON_OBJECT_AS_ARRAY));
    }

    public function extendsSchema(array &$schema): void
    {
        $query = $schema['query'] ?? '';
        if ($query === '') {
            $schema['columns'] = [];
            $schema['count'] = 0;
            return;
        }

        $queryObject = Stream\Provider::fromFile(
            $this->getFilesPath() . DIRECTORY_SEPARATOR . $schema['name'],
            Format::from($schema['type'])
        )->query();

        $counter = 0;
        $arrayKeys = [];
        foreach ($queryObject->selectAll()->from($query)->execute(StreamResults::class)->getIterator() as $item) {
            $counter++;
            foreach (array_keys($item) as $key) {
                $type = Type::match($item[$key]);
                if ($type === Type::ARRAY && isset($item[$key]['@attributes']) && isset($item[$key]['value'])) {
                    $type = Type::match($item[$key]['value']);
                    $key = $key . '.value';
                }

                if (isset($arrayKeys[$key]) === false) {
                    $arrayKeys[$key] = [$type->value];
                    continue;
                }

                if (in_array($type->value, $arrayKeys[$key], true) === false) {
                    $arrayKeys[$key][] = $type->value;
                }
            }
        }

        $schema['columns'] = array_map(function ($key) use ($arrayKeys) {
            return [
                'column' => $key,
                'types' => $arrayKeys[$key]
            ];
        }, array_keys($arrayKeys));
        $schema['count'] = $counter;
    }

    public function logQuery(string $query, ?Interface\Query $queryObject = null): void
    {
        $insert = [
            'date' => (new \DateTime())->format(\DateTimeInterface::RFC3339),
            'query' => $this->normalizeQuery($query),
        ];

        if ($queryObject !== null) {
            $insert['runs'] = $this->normalizeQuery((string) $queryObject);
        }

        file_put_contents(
            $this->getHistoryPath() . DIRECTORY_SEPARATOR . date('Y-m-d') . '.ndjson',
            json_encode($insert) . PHP_EOL,
            FILE_APPEND
        );
    }

    public function getFilesSchemas(): array
    {
        $schemas = [];
        foreach (scandir($this->getSchemasPath()) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $schemas[] = json_decode(
                file_get_contents($this->getSchemasPath() . DIRECTORY_SEPARATOR . $file),
                true,
                flags: JSON_OBJECT_AS_ARRAY
            );
        }

        return $schemas;
    }

    public function getSchema(string $uuid)
    {
        foreach ($this->getFilesSchemas() as $schema) {
            if ($schema['uuid'] === $uuid) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * @throws FileNotFoundException
     */
    public function removeFileByGuid(string $uuid): void
    {
        $schema = $this->getSchema($uuid);
        if ($schema === null) {
            throw new FileNotFoundException('Schema of file not found');
        }

        $filePath = $this->getFilesPath() . DIRECTORY_SEPARATOR . $schema['name'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $schemaPath = $this->getSchemasPath() . DIRECTORY_SEPARATOR . $schema['name'] . '.json';
        if (file_exists($schemaPath)) {
            unlink($schemaPath);
        }
    }

    /**
     * @phpstan-type HistoryRow array{
     *     date: string,
     *     created_at: string,
     *     query: string
     * }
     *
     * @return HistoryRow[]
     * @throws FileNotFoundException
     * @throws InvalidFormatException
     * @throws \DateMalformedStringException
     * @throws \Exception
     */
    public function getHistory(?\DateTime $date = null, string $last = '-7 days'): array
    {
        $historyFiles = [];
        foreach (scandir($this->getHistoryPath(), SCANDIR_SORT_DESCENDING) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fileDate = \DateTime::createFromFormat('Y-m-d', basename($file, '.ndjson'));
            if (
                $fileDate === false
                || $fileDate->getTimestamp() < (new \DateTime($last))->getTimestamp()
                || pathinfo($file, PATHINFO_EXTENSION) !== 'ndjson'
            ) {
                unlink($this->getHistoryPath() . DIRECTORY_SEPARATOR . $file);
                continue;
            }

            if ($date === null || $fileDate->format('Y-m-d') === $date->format('Y-m-d')) {
                $historyFiles[] = $this->getHistoryPath() . DIRECTORY_SEPARATOR . $file;;
            }
        }

        $historyResponse = [];
        foreach ($historyFiles as $historyFile) {
            $fileDate = \DateTime::createFromFormat('Y-m-d', basename($historyFile, '.ndjson'));
            if ($fileDate === false) {
                continue;
            }

            $historyResult = \FQL\Stream\Provider::fromFile($historyFile)->query()
                ->select('date')->as('created_at')
                ->select('query')
                ->coalesce('runs', 'query')->as('runs')
                ->orderBy('created_at')->desc()
                ->execute(StreamResults::class);

            foreach ($historyResult->getIterator() as $history) {
                $historyResponse[] = $history;
            }
        }

        return $historyResponse;
    }

    public function invalidateCache(): void
    {
        $files = glob($this->getCachePath() . DIRECTORY_SEPARATOR . '*.json');
        foreach ($files as $file) {
            unlink($file);
        }
    }

    private function queryResultExists(Interface\Query $query): bool
    {
        return file_exists($this->getQueryCacheFile($query));
    }

    /**
     * @throws \Exception
     */
    private function saveQueryResult(Interface\Query $queryObject): void
    {
        if (!is_writable($this->getCachePath())) {
            return;
        }

        $result = iterator_to_array($queryObject->execute()->getIterator());
        file_put_contents(
            $this->getQueryCacheFile($queryObject),
            json_encode($result)
        );
    }

    private function getQueryCacheFile(Interface\Query $queryObject): string
    {
        return $this->getCachePath() .
            DIRECTORY_SEPARATOR .
            md5((string) $queryObject) .
            '.json';
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }

    private function getFileTypeFromUploadedFile(UploadedFile $uploadedFile, ?callable $fallback = null): \FQL\Enum\Format
    {
        return \FQL\Enum\Format::from(match ($uploadedFile->getClientMediaType()) {
            'text/csv' => 'csv',
            'application/json' => 'jsonFile',
            'text/xml', 'application/xml' => 'xml',
            'application/yaml' => 'yaml',
            'application/neon' => 'neon',
            default => $fallback
                ? $fallback(pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION))
                : throw new \RuntimeException('Unsupported file type'),
        });
    }

    private function getFileTypeFromDownloadedFile(\SplFileInfo $downloadedFile, ?callable $fallback = null): \FQL\Enum\Format
    {
        return \FQL\Enum\Format::from(match (mime_content_type($downloadedFile->getPathname())) {
            'text/csv' => 'csv',
            'application/json' => 'jsonFile',
            'text/xml', 'application/xml' => 'xml',
            'application/yaml' => 'yaml',
            'application/neon' => 'neon',
            default => $fallback
                ? $fallback($downloadedFile->getExtension())
                : throw new \RuntimeException('Unsupported file type'),
        });
    }

    private function normalizeFilename(string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);

        $safeName = preg_replace('/[^\w\s.\-]/u', '_', $name);
        $safeName = trim($safeName);


        $safeName = mb_substr($safeName, 0, 100);

        // only ASCII at suffix
        $safeExt = preg_replace('/\W/', '', $ext);
        $safeExt = mb_substr($safeExt, 0, 5);

        return $safeExt ? "{$safeName}.{$safeExt}" : $safeName;
    }

    public function download(object|array|null $data)
    {
        $downloader = new Downloader();
        $downloadedFile = $downloader->downloadToFile(
            $data['url'],
            $this->getFilesPath() . DIRECTORY_SEPARATOR . $data['name']
        );
        $format = $this->getFileTypeFromDownloadedFile($downloadedFile, [\FQL\Enum\Format::class, 'fromExtension']);
        $schema = $this->createSchemaFromDownloadedFile($downloadedFile, $format);
        $schema['encoding'] = $data['encoding'] ?? null;
        $schema['delimiter'] = $data['delimiter'] ?? null;
        $schema['query'] = $data['query'] ?? null;
        chmod($downloadedFile, 0644);
        $this->saveSchema($schema);
        return $schema;
    }

    private function normalizeQuery(string $queryString): string
    {
        $queryString = preg_replace('/\s+/', ' ', $queryString);
        return trim($queryString);
    }

}

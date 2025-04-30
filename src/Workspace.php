<?php

namespace Api;

use Api\Storage\S3Sync;
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
use Tracy\Debugger;

/**
 * @phpstan-type HistoryRow array{
 *     date: string,
 *     created_at: string,
 *     query: string
 * }
 *
 * @phpstan-type Column array{
 *     column: string,
 *     types: array<string>
 * }
 *
 * @phpstan-type Schema array{
 *     uuid: string,
 *     originalName: string,
 *     name: string,
 *     type: string,
 *     encoding: ?string,
 *     size: int,
 *     delimiter: ?string,
 *     query: ?string,
 *     count: int,
 *     columns: array<string, Column>
 * }
*/
class Workspace
{
    private const string CachePath = 'cache';
    private const string FilesPath = 'files';
    private const string HistoryPath = 'history';
    private const string SchemasPath = 'schemas';

    private readonly string $rootPath;
    private readonly ?S3Sync $s3Sync;


    public function __construct(string $rootPath, ?S3Sync $s3Sync = null)
    {
        if (is_writable($rootPath) === false) {
            throw new \RuntimeException('Root path is not writable');
        }

        $this->rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);
        $this->s3Sync = $s3Sync;

        $this->initializeDirectories();

        $this->initializeDirectories();

        if ($this->shouldSynchronize()) {
            $this->synchronizeWorkspace();
        }

        $this->validateWorkspace();
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
     * @return array{0: Interface\Query, 1: string, 2: Query\FileQuery}
     * @throws InvalidFormatException
     * @throws FileNotFoundException
     * @throws \Exception
     */
    public function runQuery(string $query, ?string $fileName = null): array
    {
        if ($fileName !== null) {
            $schema = $this->getSchemaByFileName($fileName);
            if (!$schema) {
                throw new FileNotFoundException('Schema of file not found');
            }

            $queryObject = Query\Provider::fromFileQuery($this->schemaToFileQuery($schema));
        }

        $sqlQuery = new Sql\Sql(trim($query));
        $sqlQuery->setBasePath($this->getFilesPath());
        $queryObject = isset($queryObject)
            ? $sqlQuery->parseWithQuery($queryObject)
            : $sqlQuery->toQuery();

        if (is_writable($this->getCachePath())) {
            if (!$this->queryResultExists($queryObject)) {
                $this->saveQueryResult($queryObject);
            }
        }

        $originalQuery = $queryObject;
        $originalFileQuery = $originalQuery->provideFileQuery();
        if ($this->queryResultExists($queryObject)) {
            $queryObject = Stream\JsonStream::open($this->getQueryCacheFile($queryObject))->query();
        }

        $this->logQuery($query, $originalQuery);
        return [$queryObject, md5((string) $originalQuery), $originalFileQuery];
    }

    /**
     * @param Schema $schema
     * @return string
     */
    protected function schemaToFileQuery(array $schema): string
    {
        $fileQuery = '';
        if (isset($schema['type']) && $schema['type'] !== '') {
            $fileQuery .= sprintf('[%s]', $schema['type']);
        }

        $fileProperties = [];
        if (isset($schema['name']) && $schema['name'] !== '') {
            // use correct file name path
            $fileProperties[] = $this->getFilesPath() . DIRECTORY_SEPARATOR . $schema['name'];
        }

        if (isset($schema['encoding']) && $schema['encoding'] !== '') {
            $fileProperties[] = $schema['encoding'];
            if (isset($schema['delimiter']) && $schema['delimiter'] !== '') {
                $fileProperties[] = sprintf('"%s"', $schema['delimiter']);
            }
        }

        $fileQuery .= sprintf('(%s)', implode(',', $fileProperties));

        if (isset($schema['query']) && $schema['query'] !== '') {
            $fileQuery .= '.' . $schema['query'];
        }

        return $fileQuery;
    }

    public function addFileFromUploadedFile(UploadedFile $uploadedFile): array
    {
        $format = $this->getFileTypeFromUploadedFile($uploadedFile, function (string $extension) {
            $extensionEnum = \FQL\Enum\Format::fromExtension($extension);
            return $extensionEnum->value;
        });
        $schema = $this->createSchemaFromUploadedFile($uploadedFile, $format);
        $moveToPath = $this->getFilesPath() . DIRECTORY_SEPARATOR . $schema['name'];

        $uploadedFile->moveTo($moveToPath);
        chmod($moveToPath, 0644);

        $this->s3Sync?->uploadFile($moveToPath, 'files/' . $schema['name']);

        $this->saveSchema($schema);
        return $schema;
    }

    /**
     * @param UploadedFile $uploadedFile
     * @param Format $format
     * @return Schema
     */
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

    /**
     * @param \SplFileInfo $file
     * @param Format $format
     * @return Schema
     */
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

    public function saveSchema(array &$schema): void
    {
        $fileName = $this->getSchemasPath() . DIRECTORY_SEPARATOR . sprintf('%s.json', $schema['name']);
        $this->extendsSchema($schema);
        file_put_contents($fileName, json_encode($schema, JSON_OBJECT_AS_ARRAY));
        $this->s3Sync?->uploadSchema($schema, $this->getSchemasPath());
    }

    /**
     * @param Schema $schema
     * @return bool
     */
    public function extendsSchema(array &$schema): bool
    {
        $query = $schema['query'] ?? '';
        if ($query === '') {
            $schema['columns'] = [];
            $schema['count'] = 0;
            return true;
        }

        try {
            $queryObject = Query\Provider::fromFileQuery($this->schemaToFileQuery($schema));
            $counter = 0;
            $arrayKeys = [];

            foreach ($queryObject->selectAll()->execute(StreamResults::class)->getIterator() as $item) {
                $counter++;
                foreach (array_keys($item) as $key) {
                    $value = $item[$key];
                    $type = Type::match($value);

                    // XML: nested structure s '@attributes' a 'value'
                    if ($type === Type::ARRAY && isset($value['@attributes']) && array_key_exists('value', $value)) {
                        $type = Type::match($value['value']);
                        $key = $key . '.value';
                        $value = $value['value'];
                    }

                    // Ensure an entry exists for the key
                    if (!isset($arrayKeys[$key])) {
                        $arrayKeys[$key] = [];
                    }

                    // Add type if not already recorded
                    if (!in_array($type->value, $arrayKeys[$key], true)) {
                        $arrayKeys[$key][] = $type->value;
                    }

                    // Explicitly add 'null' if the value is null (and it's not already present)
                    if ($value === null && !in_array('null', $arrayKeys[$key], true)) {
                        $arrayKeys[$key][] = 'null';
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
            return true;
        } catch (\Exception $e) {
            Debugger::log($e, Debugger::ERROR);
            $schema['columns'] = [];
            $schema['count'] = 0;
            return false;
        }
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

    /**
     * @return Schema[]
     */
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

    public function getSchema(string $uuid): ?array
    {
        foreach ($this->getFilesSchemas() as $schema) {
            if ($schema['uuid'] === $uuid) {
                return $schema;
            }
        }

        return null;
    }

    public function getSchemaByFileName(string $fileName): ?array
    {
        foreach ($this->getFilesSchemas() as $schema) {
            if ($schema['name'] === $fileName) {
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
        $this->s3Sync?->deleteFile('files/' . $schema['name']);

        $schemaPath = $this->getSchemasPath() . DIRECTORY_SEPARATOR . $schema['name'] . '.json';
        if (file_exists($schemaPath)) {
            unlink($schemaPath);
        }
        $this->s3Sync?->deleteFile('schemas/' . $schema['name'] . '.json');
    }

    /**
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

        try {
            $result = iterator_to_array($queryObject->execute()->getIterator());
            file_put_contents(
                $this->getQueryCacheFile($queryObject),
                json_encode($result)
            );
        } catch (\Exception $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
        }
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
            'application/yaml', 'text/yaml' => 'yaml',
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

    /**
     * @param array $data
     * @return Schema
     * @throws InvalidFormatException
     */
    public function download(array $data): array
    {
        $downloader = new Downloader();
        $downloadedFile = $downloader->downloadToFile(
            $data['url'],
            $this->getFilesPath() . DIRECTORY_SEPARATOR . $data['name']
        );
        $format = $this->getFileTypeFromDownloadedFile($downloadedFile, function (string $extension) {
            $extensionEnum = \FQL\Enum\Format::fromExtension($extension);
            return $extensionEnum->value;
        });
        $schema = $this->createSchemaFromDownloadedFile($downloadedFile, $format);
        $schema['encoding'] = $data['encoding'] ?? null;
        $schema['delimiter'] = $data['delimiter'] ?? null;
        $schema['query'] = $data['query'] ?? null;
        chmod($downloadedFile, 0644);
        $this->s3Sync?->uploadFile($downloadedFile, 'files/' . $schema['name']);
        $this->saveSchema($schema);
        return $schema;
    }

    private function normalizeQuery(string $queryString): string
    {
        $queryString = preg_replace('/\s+/', ' ', $queryString);
        return trim($queryString);
    }

    private function isWorkspaceEmpty(): bool
    {
        return count(glob($this->getFilesPath() . '/*')) === 0 &&
            count(glob($this->getSchemasPath() . '/*.json')) === 0;
    }

    private function getSyncMarkerPath(): string
    {
        return $this->getRootPath() . '/.fiquela.sync.ok';
    }

    private function generateWorkspaceFingerprint(): string
    {
        return md5(realpath($this->getRootPath()));
    }

    private function shouldSync(string $markerPath, array $current): bool
    {
        $saved = json_decode(@file_get_contents($markerPath), true);
        return !$saved || $saved['workspace'] !== $current['workspace'] || $saved['s3'] !== $current['s3'];
    }

    private function initializeDirectories(): void
    {
        $this->ensureDirectory($this->getCachePath());
        $this->ensureDirectory($this->getFilesPath());
        $this->ensureDirectory($this->getHistoryPath());
        $this->ensureDirectory($this->getSchemasPath());
    }

    private function validateWorkspace(): void
    {
        $markerFile = $this->getSyncMarkerPath();
        if (!file_exists($markerFile)) {
            foreach ($this->getFilesSchemas() as $schema) {
                $filePath = $this->getFilesPath() . DIRECTORY_SEPARATOR . $schema['name'];

                if (!file_exists($filePath)) {
                    continue;
                }

                if (filesize($filePath) !== $schema['size']) {
                    $this->extendsSchema($schema);
                    $this->saveSchema($schema);
                }
            }

            $this->writeSyncMarker();
        }
    }

    private function writeSyncMarker(): void
    {
        $currentState = [
            'workspace' => $this->generateWorkspaceFingerprint(),
            's3' => $this->s3Sync?->getConfigFingerprint() ?? null,
            'timestamp' => (new \DateTime())->format(\DateTime::ATOM)
        ];

        file_put_contents($this->getSyncMarkerPath(), json_encode($currentState));
    }

    private function synchronizeWorkspace(): void
    {
        if ($this->s3Sync) {
            $this->isWorkspaceEmpty()
                ? $this->s3Sync->syncFromBucket($this->rootPath)
                : $this->s3Sync->syncToBucket($this->rootPath);
        }
    }

    private function shouldSynchronize(): bool
    {
        $markerFile = $this->getSyncMarkerPath();
        $currentState = [
            'workspace' => $this->generateWorkspaceFingerprint(),
            's3' => $this->s3Sync?->getConfigFingerprint() ?? null
        ];

        if (!file_exists($markerFile)) {
            return true;
        }

        $saved = json_decode(@file_get_contents($markerFile), true);

        return !$saved
            || $saved['workspace'] !== $currentState['workspace']
            || $saved['s3'] !== $currentState['s3'];
    }
}

<?php

namespace Api;

use Api\Exceptions\IntoTopLevelValidationException;
use Api\Storage\S3Sync;
use Api\Utils\Downloader;
use FQL\Enum\Format;
use FQL\Exception\FileNotFoundException;
use FQL\Exception\InvalidFormatException;
use FQL\Interface;
use FQL\Results\DescribeResult;
use FQL\Results\Stream as StreamResults;
use FQL\Query;
use FQL\Sql;
use FQL\Stream;
use FQL\Traits\Helpers\EnhancedNestedArrayAccessor;
use Psr\Log\LoggerInterface;
use Slim\Psr7\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * @phpstan-type HistoryRow array{
 *     date: string,
 *     created_at: string,
 *     query: string
 * }
 *
 * @phpstan-type Column array{
 *     column: string,
 *     types: array<string>,
 *     totalRows: int,
 *     totalTypes: int,
 *     dominant: ?string,
 *     suspicious: bool
 * }
 *
 * @phpstan-type Schema array{
 *     uuid: string,
 *     originalName: string,
 *     name: string,
 *     type: string,
 *     params: array<string, mixed>,
 *     size: int,
 *     query: ?string,
 *     count: int,
 *     columns: Column[]
 * }
*/
class Workspace
{
    use EnhancedNestedArrayAccessor;

    private const string CachePath = 'cache';
    private const string FilesPath = 'files';
    private const string HistoryPath = 'history';
    private const string SchemasPath = 'schemas';

    private readonly string $rootPath;
    private readonly bool $readonly;
    private readonly ?S3Sync $s3Sync;
    private ?string $uuid = null;

    public function __construct(private readonly LoggerInterface $logger, array $config, ?S3Sync $s3Sync = null)
    {
        $rootPath = $config['rootPath'] ?? null;
        if (!is_string($rootPath) || $rootPath === '') {
            throw new \RuntimeException('Root path must be a non-empty string');
        }

        if (is_writable($rootPath) === false) {
            throw new \RuntimeException('Root path is not writable');
        }

        $this->rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);
        $this->readonly = $config['readonly'] ?? false;
        $this->s3Sync = $s3Sync;

        $this->initializeWorkspaceUuid();
        $this->initializeDirectories();

        if ($this->shouldSynchronize()) {
            $this->synchronizeWorkspace();
        }

        $this->validateWorkspace();
    }

    public function getId(): string
    {
        if ($this->uuid === null) {
            $this->uuid = file_get_contents($this->getUuidPath());
        }

        return $this->uuid;
    }

    public function hasS3Sync(): bool
    {
        return $this->s3Sync !== null;
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
     * @throws InvalidFormatException
     * @throws FileNotFoundException
     * @throws IntoTopLevelValidationException
     */
    public function runQuery(string $query, ?string $fileName = null, bool $refresh = false): QueryResult
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

        $workspaceChanged = false;
        $intoFileQuery = $queryObject->hasInto() ? $queryObject->getInto() : null;
        if ($intoFileQuery !== null) {
            $this->validateIntoTopLevel($intoFileQuery);
        }

        $cacheFile = $this->getQueryCacheFile($queryObject);
        if ($refresh && file_exists($cacheFile)) {
            unlink($cacheFile); // invalidate
        }

        if (!$this->queryResultExists($queryObject) && is_writable($this->getCachePath())) {
            $this->saveQueryResult($queryObject);
        }

        $intoSchema = null;
        if ($intoFileQuery !== null) {
            $intoTarget = $this->queryResultExists($queryObject)
                ? Stream\JsonStream::open($cacheFile)->query()
                : $queryObject;

            $intoTarget->execute(StreamResults::class)->into($intoFileQuery);

            $intoSchema = $this->createSchemaFromIntoFileQuery($intoFileQuery);
            $this->saveSchema($intoSchema);
            $workspaceChanged = true;
        }

        $originalQuery = $queryObject;
        $originalFileQuery = $originalQuery->provideFileQuery();
        if ($this->queryResultExists($queryObject)) {
            $queryObject = Stream\JsonStream::open($cacheFile)->query();
        }

        $this->logQuery($query, $originalQuery);
        return new QueryResult(
            query: $queryObject,
            hash: md5((string) $originalQuery),
            originalFileQuery: $originalFileQuery,
            workspaceChanged: $workspaceChanged,
            intoSchema: $intoSchema,
        );
    }

    /**
     * @throws IntoTopLevelValidationException
     * @throws InvalidFormatException
     */
    private function validateIntoTopLevel(Query\FileQuery $into): void
    {
        if ($into->file === null) {
            throw new InvalidFormatException('INTO target file is missing');
        }

        $filesPath = realpath($this->getFilesPath());
        if ($filesPath === false) {
            throw new InvalidFormatException('Workspace files path is invalid');
        }

        $targetPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $into->file);
        $basePath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filesPath), DIRECTORY_SEPARATOR);
        $basePrefix = $basePath . DIRECTORY_SEPARATOR;

        if (!str_starts_with($targetPath, $basePrefix) && $targetPath !== $basePath) {
            throw new InvalidFormatException('Invalid path of file');
        }

        $relativePath = ltrim(substr($targetPath, strlen($basePath)), DIRECTORY_SEPARATOR);
        if ($relativePath === '' || dirname($relativePath) !== '.' || basename($relativePath) !== $relativePath) {
            throw new IntoTopLevelValidationException('INTO supports top-level file names only');
        }
    }

    /**
     * @param Query\FileQuery $into
     * @return Schema
     */
    private function createSchemaFromIntoFileQuery(Query\FileQuery $into): array
    {
        if ($into->file === null || $into->extension === null) {
            throw new InvalidFormatException('INTO target file is invalid');
        }

        $fileName = basename($into->file);
        $filePath = $this->getFilesPath() . DIRECTORY_SEPARATOR . $fileName;
        if (!file_exists($filePath)) {
            throw new FileNotFoundException(sprintf('INTO file "%s" was not created', $fileName));
        }

        return [
            'uuid' => Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), $fileName)->toRfc4122(),
            'originalName' => $fileName,
            'name' => $fileName,
            'type' => $into->extension->value,
            'params' => $into->params,
            'size' => (int) filesize($filePath),
            'query' => $into->query,
            'count' => 0,
            'columns' => [],
        ];
    }

    /**
     * @param Schema $schema
     * @return string
     * @throws InvalidFormatException
     */
    protected function schemaToFileQuery(array $schema): string
    {
        $format = $schema['type'] ?? '';
        $filePath = $this->getFilesPath() . DIRECTORY_SEPARATOR . $schema['name'];

        $paramParts = [$filePath];
        $params = $schema['params'] ?? [];

        if ($params !== [] && $format !== '') {
            $formatEnum = Format::fromExtension($format);
            $defaults = $formatEnum->getDefaultParams();

            foreach ($params as $key => $value) {
                if (isset($defaults[$key]) && $defaults[$key] === $value) {
                    continue;
                }
                if ($value !== null && $value !== '') {
                    $paramParts[] = sprintf('%s: "%s"', $key, $value);
                }
            }
        }

        $fileQuery = $format . sprintf('(%s)', implode(', ', $paramParts));

        if (isset($schema['query']) && $schema['query'] !== '') {
            $fileQuery .= '.' . $schema['query'];
        }

        return $fileQuery;
    }

    public function addFileFromUploadedFile(UploadedFile $uploadedFile): array
    {
        if ($this->isReadonly()) {
            throw new \RuntimeException('Workspace is read-only');
        }

        $format = $this->getFileTypeFromUploadedFile($uploadedFile, function (string $extension) {
            $extensionEnum = Format::fromExtension($extension);
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
            'type' => $format->value,
            'params' => [],
            'size' => $uploadedFile->getSize(),
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
            'type' => $format->value,
            'params' => [],
            'size' => $file->getSize(),
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
        if ($this->isWritable()) {
            $this->s3Sync?->uploadSchema($schema, $this->getSchemasPath());
        }
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
            $describeResult = $queryObject->describe()->execute();

            if ($describeResult instanceof DescribeResult) {
                $schema['columns'] = iterator_to_array($describeResult->getIterator());
                $schema['count'] = $describeResult->getSourceRowCount();
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error while extending schema', ['exception' => $e]);
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

            $schema = json_decode(
                file_get_contents($this->getSchemasPath() . DIRECTORY_SEPARATOR . $file),
                true,
                flags: JSON_OBJECT_AS_ARRAY
            );

            if ($this->migrateSchema($schema)) {
                $this->saveSchema($schema);
            }

            $schemas[] = $schema;
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
        if ($this->isReadonly()) {
            throw new \RuntimeException('Workspace is read-only');
        }

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
            $this->logger->error('Error while saving query result', ['exception' => $e]);
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

    private function getFileTypeFromUploadedFile(UploadedFile $uploadedFile, ?callable $fallback = null): Format
    {
        return Format::from(match ($uploadedFile->getClientMediaType()) {
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

    private function getFileTypeFromDownloadedFile(\SplFileInfo $downloadedFile, ?callable $fallback = null): Format
    {
        return Format::from(match (mime_content_type($downloadedFile->getPathname())) {
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
        $safeExt = mb_substr($safeExt, 0, 8);

        return $safeExt ? "{$safeName}.{$safeExt}" : $safeName;
    }

    /**
     * @param array $data
     * @return Schema
     * @throws InvalidFormatException
     */
    public function download(array $data): array
    {
        if ($this->isReadonly()) {
            throw new \RuntimeException('Workspace is read-only');
        }

        $downloader = new Downloader();
        $downloadedFile = $downloader->downloadToFile(
            $data['url'],
            $this->getFilesPath() . DIRECTORY_SEPARATOR . $data['name']
        );
        $format = $this->getFileTypeFromDownloadedFile($downloadedFile, function (string $extension) {
            $extensionEnum = Format::fromExtension($extension);
            return $extensionEnum->value;
        });
        $schema = $this->createSchemaFromDownloadedFile($downloadedFile, $format);
        $schema['params'] = $data['params'] ?? [];
        $schema['query'] = $data['query'] ?? null;

        if ($schema['params'] !== []) {
            $format->validateParams($schema['params']);
        }
        chmod($downloadedFile, 0644);
        $this->s3Sync?->uploadFile($downloadedFile, 'files/' . $schema['name']);
        $this->saveSchema($schema);
        return $schema;
    }

    private function migrateSchema(array &$schema): bool
    {
        if (array_key_exists('params', $schema)) {
            return false;
        }

        $params = [];
        if (!empty($schema['encoding'])) {
            $params['encoding'] = $schema['encoding'];
        }
        if (!empty($schema['delimiter'])) {
            $params['delimiter'] = $schema['delimiter'];
        }

        $schema['params'] = $params;
        unset($schema['encoding'], $schema['delimiter']);
        return true;
    }

    private function normalizeQuery(string $queryString): string
    {
        return trim($queryString);
    }

    private function isWorkspaceEmpty(): bool
    {
        return count(glob($this->getFilesPath() . '/*')) === 0 &&
            count(glob($this->getSchemasPath() . '/*.json')) === 0;
    }

    private function getUuidPath(): string
    {
        return $this->getRootPath() . DIRECTORY_SEPARATOR . '.fiquela.uuid';
    }

    private function getSyncMarkerPath(): string
    {
        return $this->getRootPath() . '/.fiquela.sync.ok';
    }

    private function generateWorkspaceFingerprint(): string
    {
        return md5(realpath($this->getRootPath()));
    }

    public function isReadonly(): bool
    {
        return $this->readonly;
    }

    public function isWritable(): bool
    {
        return !$this->isReadonly();
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
        if (file_exists($this->getUuidPath()) === false) {
            throw new \RuntimeException('Workspace UUID file not found');
        }

        $markerFile = $this->getSyncMarkerPath();
        if (file_exists($markerFile)) {
            return;
        }

        if ($this->isWritable()) {
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
        }

        $this->writeSyncMarker();
    }

    private function initializeWorkspaceUuid(): void
    {
        $uuidPath = $this->getUuidPath();
        if (!file_exists($uuidPath)) {
            $uuid = Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), md5($this->getRootPath()));
            file_put_contents($uuidPath, $uuid->toRfc4122());
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

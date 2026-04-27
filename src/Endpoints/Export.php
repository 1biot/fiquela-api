<?php

namespace Api\Endpoints;

use Api;
use FQL;
use Nette\Schema\ValidationException;
use Slim\Exception;
use Slim\Psr7;

class Export extends Controller
{
    /** @var array<string, array{ext: string, contentType: string, allowed: list<string>}> */
    private const FormatSpec = [
        'json' => [
            'ext' => 'json',
            'contentType' => 'application/json',
            'allowed' => [],
        ],
        'ndjson' => [
            'ext' => 'ndJson',
            'contentType' => 'application/x-ndjson',
            'allowed' => [],
        ],
        'csv' => [
            'ext' => 'csv',
            'contentType' => 'text/csv',
            'allowed' => ['delimiter', 'encoding', 'useHeader', 'enclosure', 'bom'],
        ],
        'xml' => [
            'ext' => 'xml',
            'contentType' => 'application/xml',
            'allowed' => ['encoding'],
        ],
        'xlsx' => [
            'ext' => 'xls',
            'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'allowed' => [],
        ],
        'ods' => [
            'ext' => 'ods',
            'contentType' => 'application/vnd.oasis.opendocument.spreadsheet',
            'allowed' => [],
        ],
    ];

    public function __invoke(Psr7\Request $request, Psr7\Response $response, array $args): Psr7\Response
    {
        try {
            $workspace = $this->getWorkspace($request);
            $resultsHash = $args['hash'] ?? false;
            if (!$resultsHash) {
                throw new \RuntimeException('Missing results hash');
            }

            $data = $this->validateQueryParams($request, new Api\Schemas\Export);
            $format = strtolower((string) $data['format']);
            $spec = self::FormatSpec[$format];

            $params = [];
            foreach ($spec['allowed'] as $key) {
                if (isset($data[$key]) && $data[$key] !== '') {
                    $params[$key] = (string) $data[$key];
                }
            }

            $exportPath = $workspace->exportCachedQuery($resultsHash, $spec['ext'], $params);
            $cacheFile = $workspace->getCachePath() . DIRECTORY_SEPARATOR . $resultsHash . '.json';

            $stream = fopen($exportPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open exported file');
            }

            // Generated temp files (everything except the json cache file itself) are removed
            // once the response stream closes — keeps sys_get_temp_dir() clean.
            if ($exportPath !== $cacheFile) {
                register_shutdown_function(static function () use ($exportPath): void {
                    if (file_exists($exportPath)) {
                        @unlink($exportPath);
                    }
                });
            }

            $response = $response->withBody(new Psr7\Stream($stream))
                ->withHeader('Content-Type', $spec['contentType']);

            if ($this->isTruthy($data['force'] ?? null)) {
                $response = $response->withHeader(
                    'Content-Disposition',
                    'attachment; filename="' . $resultsHash . '.' . $spec['ext'] . '"'
                );
            }

            return $response;
        } catch (ValidationException $e) {
            throw new Api\Exceptions\UnprocessableContentHttpException($request, previous: $e);
        } catch (FQL\Exception\FileNotFoundException $e) {
            throw new Exception\HttpNotFoundException($request, previous: $e);
        } catch (FQL\Exception\InvalidFormatException $e) {
            throw new Exception\HttpBadRequestException($request, previous: $e);
        } catch (\Throwable $e) {
            throw new Exception\HttpInternalServerErrorException($request, previous: $e);
        }
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }
}

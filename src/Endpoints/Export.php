<?php

namespace Api\Endpoints;

use FQL;
use League\Csv;
use Slim\Psr7;

class Export extends Controller
{
    public function __invoke(Psr7\Request $request, Psr7\Response $response, array $args): Psr7\Response
    {
        try {
            $workspace = $this->getWorkspace($request);
            $resultsHash = $args['hash'] ?? false;
            if (!$resultsHash) {
                throw new \RuntimeException('Missing results hash');
            }

            $file = $workspace->getCachePath() . DIRECTORY_SEPARATOR . sprintf('%s.json', $resultsHash);
            if (file_exists($file) === false) {
                throw new \RuntimeException('Invalid results hash');
            }

            $format = strtolower($request->getQueryParams()['format'] ?? 'json');
            $response = match ($format) {
                'json', 'ndjson' => $this->createJsonResponse($response, $format, $file),
                'csv', 'tsv' => $this->createDelimitedFileResponse(
                    $response,
                    $format,
                    $file,
                    $request->getQueryParams()['delimiter'] ?? null
                ),
                default => throw new \RuntimeException('Unsupported format: ' . $format),
            };

            $forceDownload = $request->getQueryParams()['force'] ?? false;
            if ($forceDownload) {
                return $response->withHeader('Content-Disposition', 'attachment; filename="' . basename($file) . '"');
            }

            return $response;
        } catch (\RuntimeException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 500);
        } catch (FQL\Exception\FileNotFoundException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 404);
        } catch (Csv\CannotInsertRecord|Csv\InvalidArgument|FQL\Exception\InvalidFormatException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @throws FQL\Exception\InvalidFormatException
     * @throws FQL\Exception\FileNotFoundException
     * @throws \Exception
     */
    private function createJsonResponse(Psr7\Response $response, string $format, string $file): Psr7\Response
    {
        $stream = null;
        if ($format === 'json') {
            $stream = fopen($file, 'rb');
        } elseif ($format === 'ndjson') {
            $stream = fopen('php://temp', 'r+');
            $results = FQL\Query\Provider::fromFile($file)->selectAll()->execute(FQL\Results\Stream::class);
            foreach ($results->getIterator() as $row) {
                fwrite($stream, json_encode($row) . "\n");
            }
            rewind($stream);
        }

        if ($stream === false || $stream === null) {
            throw new \RuntimeException('Failed to create a stream');
        }

        $contentType = match ($format) {
            'json' => 'application/json',
            'ndjson' => 'application/x-ndjson',
        };

        return $response->withBody(new Psr7\Stream($stream))
            ->withHeader('Content-Type', $contentType);
    }

    /**
     * @throws Csv\InvalidArgument
     * @throws FQL\Exception\FileNotFoundException
     * @throws FQL\Exception\InvalidFormatException
     * @throws Csv\CannotInsertRecord
     * @throws Csv\Exception
     * @throws \Exception
     */
    private function createDelimitedFileResponse(
        Psr7\Response $response,
        string $format,
        string $file,
        ?string $delimiter
    ): Psr7\Response {
        $stream = fopen('php://temp', 'r+');
        $csv = Csv\Writer::createFromStream($stream);
        $csv->setDelimiter($delimiter ?? ($format === 'csv' ? ',' : "\t"));

        $hasWrittenHeader = false;
        $results = FQL\Query\Provider::fromFile($file)->selectAll()->execute(FQL\Results\Stream::class);
        foreach ($results->getIterator() as $row) {
            if ($hasWrittenHeader === false) {
                $csv->insertOne(array_keys($row));
                $hasWrittenHeader = true;
            }

            $csv->insertOne($row);
        }

        rewind($stream);
        return $response->withBody(new Psr7\Stream($stream))
            ->withHeader('Content-Type', sprintf('text/%s', $format));
    }
}

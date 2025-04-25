<?php

namespace Api\Endpoints;

use Api;
use FQL;
use Slim\Psr7;
use Tracy\Debugger;

/**
 * @phpstan-import-type Schema from Api\Workspace
 */
class Files extends Controller
{
    public function list(Psr7\Request $request, Psr7\Response $response): Psr7\Response
    {
        try {
            $workspace = $this->getWorkspace($request);
            return $this->json($response, $workspace->getFilesSchemas());
        } catch (\RuntimeException $e) {
            Debugger::log($e, Debugger::ERROR);
            return $this->json($response, ['error' => 'Could not list a files'], 500);
        }
    }

    public function insert(Psr7\Request $request, Psr7\Response $response, array $args): Psr7\Response
    {
        try {
            $insertType = $request->getHeaderLine('Content-Type');
            if (str_starts_with($insertType, 'multipart/form-data')) {
                return $this->upload($request, $response, $args);
            } elseif (str_starts_with($insertType, 'application/json')) {
                return $this->download($request, $response, $args);
            }

            throw new \RuntimeException('Unsupported content type: ' . $insertType);
        } catch (\RuntimeException $e) {
            Debugger::log($e, Debugger::ERROR);
            return $this->json($response, ['error' => $e->getMessage()], 500);
        }
    }

    public function detail(Psr7\Request $request, Psr7\Response $response, array $args): Psr7\Response
    {
        try {
            $workspace = $this->getWorkspace($request);
            $schema = $this->validateFile($workspace, $args);
            $raw = (bool) ($request->getQueryParams()['raw'] ?? null);
            if (!$raw) {
                return $this->json($response, $schema);
            }

            $filePath = $workspace->getFilesPath() . DIRECTORY_SEPARATOR . $schema['name'];
            if (!file_exists($filePath)) {
                return $this->json($response, ['error' => 'File not found'], 404);
            }

            $filename = $schema['originalName'] ?? $schema['name'];
            return $response
                ->withBody(new Psr7\Stream(fopen($filePath, 'rb')))
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('Content-Disposition', 'attachment; filename="' . basename($filename) . '"')
                ->withHeader('Content-Length', (string) filesize($filePath));

        } catch (\RuntimeException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 404);
        }
    }

    public function update(Psr7\Request $request, Psr7\Response $response, array $args): Psr7\Response
    {
        try {
            $workspace = $this->getWorkspace($request);
            $schema = $this->validateFile($workspace, $args);
            $data = $this->validateRequest($request, new Api\Schemas\UpdateFile);
            $updatedSchema = array_merge($schema, $data);
            $workspace->saveSchema($updatedSchema);
            return $this->json($response, $updatedSchema);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 500);
        }
    }

    public function delete(Psr7\Request $request, Psr7\Response $response, array $args): Psr7\Response
    {
        try {
            $workspace = $this->getWorkspace($request);
            $schema = $this->validateFile($workspace, $args);
            $workspace->removeFileByGuid($schema['uuid']);
            $workspace->invalidateCache();
            return $this->json($response, ['message' => 'File deleted']);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 500);
        } catch (FQL\Exception\FileNotFoundException) {
            return $this->json($response, ['error' => 'File not found'], 404);
        }
    }

    /**
     * @throws \RuntimeException
     * @param array{uuid: ?string} $args
     * @return Schema
     */
    private function validateFile(Api\Workspace $workspace, array $args): array
    {
        $uuid = $args['uuid'] ?? null;
        if ($uuid === null) {
            throw new \RuntimeException('File not found');
        }

        $schema = $workspace->getSchema($uuid);
        if ($schema === null) {
            throw new \RuntimeException('Schema not found');
        }

        return $schema;
    }

    private function upload(Psr7\Request $request, Psr7\Response $response, array $args): Psr7\Response
    {
        try {
            $workspace = $this->getWorkspace($request);
            return $this->json(
                $response,
                [
                    'schema' => $workspace->addFileFromUploadedFile(
                        $this->getUploadedFile($request)
                    )
                ]
            );
        } catch (\Throwable $e) {
            Debugger::log($e, Debugger::ERROR);
            return $this->json($response, ['error' => $e->getMessage()], 500);
        }
    }

    private function getUploadedFile(Psr7\Request $request): Psr7\UploadedFile
    {
        $uploadedFiles = $request->getUploadedFiles();

        /** @var Psr7\UploadedFile $uploadedFile */
        $uploadedFile = $uploadedFiles['file'] ?? null;
        if ($uploadedFile === null) {
            throw new \RuntimeException('No file uploaded');
        }

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('File upload error');
        }

        return $uploadedFile;
    }

    private function download(Psr7\Request $request, Psr7\Response $response, array $args): Psr7\Response
    {
        try {
            $workspace = $this->getWorkspace($request);
            $schema = $workspace->download($this->validateRequest($request, new Api\Schemas\DownloadFile));
            return $this->json($response, ['schema' => $schema]);
        } catch (\Throwable $e) {
            Debugger::log($e, Debugger::ERROR);
            return $this->json($response, ['error' => 'Could not download a file'], 500);
        }
    }
}

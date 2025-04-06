<?php

namespace Api\Endpoints;

use Api\Workspace;
use Nette\Schema\Processor;
use Api\Schemas\Schema;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

abstract class Controller
{
    protected function getWorkspace(Request $request): Workspace
    {
        $workspace = $request->getAttribute('workspace');
        if (!$workspace) {
            throw new \RuntimeException('Invalid workspace');
        }

        return $workspace;
    }

    protected function validateRequest(Request $request, Schema $schema): array
    {
        return (new Processor)->process($schema->getSchema(), $request->getParsedBody());
    }

    protected function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

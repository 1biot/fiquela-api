<?php

namespace Api\Endpoints;

use Api\Exceptions\WorkspaceException;
use Api\Workspace;
use Api\Schemas\Schema;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

abstract class Controller
{
    /**
     * @throws WorkspaceException
     */
    protected function getWorkspace(Request $request): Workspace
    {
        $workspace = $request->getAttribute('workspace');
        if (!$workspace) {
            throw new WorkspaceException('Request missing "workspace" attribute');
        }

        return $workspace;
    }

    /**
     * @param Request $request
     * @param Schema $schema
     * @return array
     * @throws ValidationException
     */
    protected function validateRequest(Request $request, Schema $schema): array
    {
        return (new Processor)->process($schema->getSchema(), $request->getParsedBody());
    }

    protected function json(Response $response, array $data, int $status = 200): Response
    {
        $responseContent = $response->getBody();
        $responseContent->write(json_encode($data));
        return $response->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($responseContent);
    }
}

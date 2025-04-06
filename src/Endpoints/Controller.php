<?php

namespace Api\Endpoints;

use Api\Workspace;
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

    protected function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

<?php

namespace Api\Endpoints;

use Api\Bootstrap;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class Status extends Controller
{
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $workspace = $this->getWorkspace($request);
        return $this->json(
            $response,
            [
                'status' => 'ok',
                'timestamp' => (new \DateTime())->format('c'),
                'version' => Bootstrap::getVersion(),
                'fiquela_version' => \Composer\InstalledVersions::getPrettyVersion('1biot/fiquela'),
                'workspace' => [
                    'id' => $workspace->getId(),
                    's3' => $workspace->hasS3Sync(),
                ]
            ]
        );
    }
}

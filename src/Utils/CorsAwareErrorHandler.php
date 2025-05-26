<?php

namespace Api\Utils;

use Psr\Http\Message\ResponseInterface;
use Slim\Handlers\ErrorHandler;

class CorsAwareErrorHandler extends ErrorHandler
{
    protected function respond(): ResponseInterface
    {
        $response = parent::respond();
        $origin = $this->request->getHeaderLine('Origin') ?: '*';
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    }
}

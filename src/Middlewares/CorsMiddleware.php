<?php

namespace Api\Middlewares;

use Psr\Http;
use Slim\Psr7;

class CorsMiddleware implements Http\Server\MiddlewareInterface
{
    public function process(
        Http\Message\ServerRequestInterface $request,
        Http\Server\RequestHandlerInterface $handler
    ): Http\Message\ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $origin = $origin !== '' ? $origin : '*';

        if ($request->getMethod() === 'OPTIONS') {
            return $this
                ->applyCorsHeaders(new Psr7\Response(204), $origin)
                ->withHeader('Content-Length', '0');
        }

        $response = $handler->handle($request);
        return $this->applyCorsHeaders($response, $origin);
    }

    private function applyCorsHeaders(Http\Message\ResponseInterface $response, string $origin): Http\Message\ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With')
            ->withHeader('Access-Control-Allow-Credentials', 'true');
    }
}

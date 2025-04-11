<?php

namespace Api\Middlewares;

use Api\Auth\AuthenticatorFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class AuthMiddleware
{
    public function __construct(private readonly AuthenticatorFactory $factory) {}

    public function __invoke(Request $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $workspace = $this->factory->authenticate($request);
        if (!$workspace) {
            $res = new Response();
            $res->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        return $handler->handle($request->withAttribute('workspace', $workspace));
    }
}

<?php

namespace Api\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

class AuthMiddleware
{
    public function __construct(private AuthenticatorFactory $factory) {}

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
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

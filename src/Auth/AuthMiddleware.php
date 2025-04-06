<?php

namespace Api\Auth;

use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Psr7\Request;

class AuthMiddleware
{
    public function __construct(private AuthenticatorFactory $factory) {}

    public function __invoke(Request $request, RequestHandlerInterface $handler): Response
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

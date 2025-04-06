<?php

namespace Api\Auth;

use Api\Workspace;
use Psr\Http\Message\ServerRequestInterface;

class TokenAuthenticator implements AuthenticatorInterface
{
    private string $token;
    private Workspace $workspace;

    public function __construct(string $apiKey, Workspace $workspace)
    {
        $this->token = password_hash($apiKey, PASSWORD_BCRYPT);
        $this->workspace = $workspace;
    }

    public function supports(ServerRequestInterface $request): bool
    {
        return str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ');
    }

    public function authenticate(ServerRequestInterface $request): ?Workspace
    {
        $token = substr($request->getHeaderLine('Authorization'), 7);
        if (password_verify($token, $this->token)) {
            return $this->workspace;
        }

        return null;
    }
}

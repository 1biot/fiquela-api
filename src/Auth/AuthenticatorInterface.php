<?php

namespace Api\Auth;

use Api\Workspace;
use Psr\Http\Message\ServerRequestInterface;

interface AuthenticatorInterface
{
    public function supports(ServerRequestInterface $request): bool;
    public function authenticate(ServerRequestInterface $request): ?Workspace;
}

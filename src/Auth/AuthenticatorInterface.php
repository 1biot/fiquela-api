<?php

namespace Api\Auth;

use Api;
use Psr;

interface AuthenticatorInterface
{
    public function supports(Psr\Http\Message\ServerRequestInterface $request): bool;
    public function authenticate(Psr\Http\Message\ServerRequestInterface $request): ?Api\Workspace;
    public function login(array $credentials): ?string;
}

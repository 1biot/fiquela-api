<?php

namespace Api\Auth;

use Api;
use Firebase\JWT;
use Psr;
use Random\RandomException;

readonly class SingleUserJwtAuthenticator implements AuthenticatorInterface
{
    private string $secretFile;

    public function __construct(
        private string $username,
        private string $passwordHash,
        private Api\Workspace $workspace,
        private readonly Psr\Log\LoggerInterface $logger
    ) {
        $this->secretFile = __DIR__ . '/../../temp/jwt_secret.json';
    }

    public function supports(Psr\Http\Message\ServerRequestInterface $request): bool
    {
        return str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ');
    }

    public function authenticate(Psr\Http\Message\ServerRequestInterface $request): ?Api\Workspace
    {
        $auth = $request->getHeaderLine('Authorization');
        $token = substr($auth, 7);

        try {
            $payload = JWT\JWT::decode($token, new JWT\Key($this->getSecret(), 'HS256'));

            // Basic payload validation
            if (!isset($payload->sub) || $payload->sub !== 'api-token') {
                return null;
            } elseif (!isset($payload->iss) || $payload->iss !== $this->getIssuer()) {
                return null;
            } elseif (!isset($payload->username) || $payload->username !== $this->username) {
                return null;
            } elseif (!isset($payload->workspace) || $payload->workspace !== $this->workspace->getId()) {
                return null;
            }

            return $this->workspace;
        } catch (\Throwable $e) {
            $this->logger->error('JWT decode error', ['exception' => $e]);
            return null;
        }
    }

    public function login(array $credentials): ?string
    {
        [$username, $password] = array_values($credentials);
        if ($username !== $this->username || !password_verify($password, $this->passwordHash)) {
            return null;
        }

        try {
            return JWT\JWT::encode($this->createJwtPayload($username), $this->getSecret(), 'HS256');
        } catch (\Throwable $e) {
            $this->logger->error('JWT encode error', ['exception' => $e]);
            return null;
        }
    }

    /**
     * @throws RandomException
     */
    public function rotateSecretAndGetNewToken(string $username): string
    {
        $newSecret = bin2hex(random_bytes(32));
        file_put_contents($this->secretFile, json_encode(['secret' => $newSecret]));
        return JWT\JWT::encode($this->createJwtPayload($username), $this->getSecret(), 'HS256');
    }

    private function createJwtPayload(string $username): array
    {
        $now = time();
        return [
            'iss' => $this->getIssuer(),
            'sub' => 'api-token',
            'username' => $username,
            'roles' => ['owner'],
            'workspace' => $this->workspace->getId(),
            'iat' => $now,
            'exp' => $now + 43200, // 12h
        ];
    }

    /**
     * @throws RandomException
     */
    private function getSecret(): string
    {
        if (!file_exists($this->secretFile)) {
            $new = ['secret' => bin2hex(random_bytes(32))];
            file_put_contents($this->secretFile, json_encode($new));
        }

        $data = json_decode(file_get_contents($this->secretFile), true);
        return $data['secret'] ?? '';
    }

    private function getIssuer(): string
    {
        return $_SERVER['SERVER_NAME']
        . (in_array($_SERVER['SERVER_PORT'], [80, 443]) ? '' : (':' . $_SERVER['SERVER_PORT']));
    }
}

<?php

namespace Api\Endpoints;

use Api\Auth\AuthenticatorFactory;
use Api\Auth\SingleUserJwtAuthenticator;
use Api\Exceptions\UnprocessableContentHttpException;
use Nette\Schema\ValidationException;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7;

final class Auth extends Controller
{
    public function __construct(private readonly AuthenticatorFactory $authenticatorFactory)
    {
    }

    public function login(Psr7\Request $request, Psr7\Response $response): Psr7\Response
    {
        try {
            $data = $this->validateRequest($request, new \Api\Schemas\Auth());
            $token = $this->authenticatorFactory->login($data);
            if (!$token) {
                throw new HttpUnauthorizedException($request);
            }

            return $this->json($response, ['token' => $token]);
        } catch (ValidationException $e) {
            throw new UnprocessableContentHttpException($request, previous: $e);
        } catch (\Throwable $e) {
            throw new HttpInternalServerErrorException($request, previous: $e);
        }
    }

    public function revoke(Psr7\Request $request, Psr7\Response $response): Psr7\Response
    {
        try {
            $data = $this->validateRequest($request, new \Api\Schemas\Auth());
            $token = $this->authenticatorFactory->login($data);
            if (!$token) {
                throw new HttpUnauthorizedException($request);
            }

            $loginProvider = $this->authenticatorFactory->getLoginProvider();
            if (!$loginProvider instanceof SingleUserJwtAuthenticator) {
                throw new \RuntimeException('Invalid login provider');
            }
            return $this->json(
                $response,
                [
                    'revoked' => $loginProvider->rotateSecretAndGetNewToken($data['username'])
                ]
            );
        } catch (ValidationException $e) {
            throw new UnprocessableContentHttpException($request, previous: $e);
        } catch (\Throwable $e) {
            throw new HttpInternalServerErrorException($request, previous: $e);
        }
    }
}

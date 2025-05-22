<?php

namespace Api\Renderers;

use Api\Exceptions\UnprocessableContentHttpException;
use Slim\Exception\HttpSpecializedException;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

class ErrorRenderer implements ErrorRendererInterface
{
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        $showDetails = $displayErrorDetails || $exception instanceof HttpSpecializedException;
        $response = [
            'error' => $exception instanceof HttpSpecializedException
                ? $exception->getTitle()
                : '500 Internal Server Error',
            'code' => $exception instanceof HttpSpecializedException
                ? $exception->getCode()
                : 500,
            'message' => $exception instanceof HttpSpecializedException
                ? $exception->getMessage()
                : 'Internal server error.',
            'description' => $exception instanceof HttpSpecializedException
                ? $exception->getDescription()
                : 'Unexpected condition encountered preventing server from fulfilling request.',
        ];

        if ($exception instanceof UnprocessableContentHttpException) {
            $response['details'] = $exception->getValidationErrors();
        } elseif ($showDetails) {
            $response['details'] = $exception->getTrace();
        }

        return json_encode($response, JSON_PRETTY_PRINT);
    }
}

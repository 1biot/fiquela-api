<?php

namespace Api\Renderers;

use Slim\Exception\HttpSpecializedException;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

class ErrorRenderer implements ErrorRendererInterface
{
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        $showDetails = $displayErrorDetails || $exception instanceof HttpSpecializedException;
        return json_encode(
            [
                'error' => $exception instanceof HttpSpecializedException
                    ? $exception->getTitle()
                    : '500 Internal Server Error',
                'code' => $showDetails
                    ? $exception->getCode()
                    : 500,
                'message' => $showDetails
                    ? $exception->getMessage()
                    : 'Internal server error.',
                'description' => $exception instanceof HttpSpecializedException
                    ? $exception->getDescription()
                    : (
                    $showDetails
                        ? $exception->getTraceAsString()
                        : 'Unexpected condition encountered preventing server from fulfilling request.'
                    ),

            ],
            JSON_PRETTY_PRINT
        );
    }
}

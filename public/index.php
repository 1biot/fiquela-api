<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Slim\Factory\AppFactory::setContainer(Api\Bootstrap::createContainer());
$app = Slim\Factory\AppFactory::create();

$app->addBodyParsingMiddleware();
Api\Bootstrap::addRouting($app);
$app->addErrorMiddleware(false, true, true);

$app->run();

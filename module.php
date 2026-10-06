<?php

declare(strict_types=1);

use Marko\Core\Container\ContainerInterface;
use Marko\Core\Error\BootstrapErrorHandler;
use Marko\Errors\Contracts\ErrorHandlerInterface;
use Marko\ErrorsSimple\SimpleErrorHandler;

// Marko-specific configuration for this module.
// Name and version come from composer.json.

return [
    'bindings' => [
        ErrorHandlerInterface::class => SimpleErrorHandler::class,
    ],
    'boot' => function (ContainerInterface $container, BootstrapErrorHandler $bootstrapErrorHandler) {
        // Get the error handler and register it in place of core's bootstrap
        // handler, so the bootstrap handler is not left registered underneath
        $handler = $container->get(ErrorHandlerInterface::class);
        $bootstrapErrorHandler->unregister();
        $handler->register();
    },
];

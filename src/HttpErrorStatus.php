<?php

declare(strict_types=1);

namespace Marko\ErrorsSimple;

use Marko\Core\Exceptions\HttpExceptionInterface;
use Throwable;

/**
 * Last-resort HTTP mapping for throwables that reach an error handler.
 *
 * HTTP exceptions are normally rendered inside the routing pipeline. One
 * thrown outside it (e.g. in a boot callback during a request) still gets its
 * own status and headers here instead of a generic 500.
 */
class HttpErrorStatus
{
    public static function statusCode(
        Throwable $throwable,
    ): int {
        return $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;
    }

    /**
     * @return array<string, string>
     */
    public static function headers(
        Throwable $throwable,
    ): array {
        return $throwable instanceof HttpExceptionInterface ? $throwable->getHeaders() : [];
    }
}

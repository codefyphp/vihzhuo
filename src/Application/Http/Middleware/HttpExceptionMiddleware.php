<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

final class HttpExceptionMiddleware extends \Codefy\Framework\Http\Middleware\Exception\HttpExceptionMiddleware
{
    protected function normalizeStatusCode(int|string $code): int
    {
        // PDO exceptions carry SQLSTATE strings, which are not HTTP status codes.
        return is_int($code) ? parent::normalizeStatusCode($code) : 500;
    }
}

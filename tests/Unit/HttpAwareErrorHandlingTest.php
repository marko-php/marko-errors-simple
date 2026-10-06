<?php

declare(strict_types=1);

namespace Marko\ErrorsSimple\Tests\Unit\HttpAware;

use DateTimeImmutable;
use Marko\Core\Exceptions\HttpExceptionInterface;
use Marko\Errors\ErrorReport;
use Marko\Errors\Severity;
use Marko\ErrorsSimple\Environment;
use Marko\ErrorsSimple\Formatters\JsonFormatter;
use Marko\ErrorsSimple\HttpErrorStatus;
use Marko\ErrorsSimple\SimpleErrorHandler;
use Marko\Testing\Fake\FakeClock;
use RuntimeException;
use Throwable;

class CapturingErrorHandler extends SimpleErrorHandler
{
    public ?int $statusCodeSet = null;

    /** @var array<string, string> */
    public array $headersSent = [];

    protected function clearOutputBuffers(): void {}

    protected function setHttpStatusCode(
        int $code,
    ): void {
        $this->statusCodeSet = $code;
    }

    protected function sendHeader(
        string $name,
        string $value,
    ): void {
        $this->headersSent[$name] = $value;
    }
}

class TeapotException extends RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        return 429;
    }

    public function getHeaders(): array
    {
        return ['Retry-After' => '30'];
    }

    public function getResponseData(): array
    {
        return ['message' => 'Slow down.'];
    }
}

/**
 * @param array<string, string> $server
 */
function webEnvironment(
    bool $production,
    array $server = ['HTTP_ACCEPT' => 'application/json'],
): Environment {
    return new Environment(
        sapi: 'fpm-fcgi',
        envVars: ['MARKO_ENV' => $production ? 'production' : 'development'],
        server: $server,
    );
}

/**
 * @return array{0: CapturingErrorHandler, 1: string}
 */
function handleWith(
    Environment $environment,
    Throwable $throwable,
): array {
    $handler = new CapturingErrorHandler($environment, new FakeClock());

    ob_start();
    $handler->handleException($throwable);
    $output = (string) ob_get_clean();

    return [$handler, $output];
}

describe('Environment::acceptsJson()', function (): void {
    it('detects a JSON-accepting request from the server Accept header', function (): void {
        expect((new Environment(server: ['HTTP_ACCEPT' => 'application/json']))->acceptsJson())->toBeTrue()
            ->and((new Environment(server: ['HTTP_ACCEPT' => 'application/vnd.api+json']))->acceptsJson())->toBeTrue()
            ->and((new Environment(server: ['CONTENT_TYPE' => 'application/json']))->acceptsJson())->toBeTrue()
            ->and(
                (new Environment(
                    server: ['HTTP_ACCEPT' => 'text/html', 'CONTENT_TYPE' => 'application/json'],
                ))->acceptsJson(),
            )->toBeFalse()
            ->and((new Environment(server: []))->acceptsJson())->toBeFalse();
    });
});

describe('JsonFormatter', function (): void {
    it('renders a generic JSON body in production', function (): void {
        $report = ErrorReport::fromThrowable(new RuntimeException('SQLSTATE secret at /var/www'), Severity::Error, new DateTimeImmutable());

        $json = (new JsonFormatter(webEnvironment(production: true)))->format($report);

        expect(json_decode($json, true))->toBe(['message' => 'Server Error'])
            ->and($json)->not->toContain('SQLSTATE')->not->toContain('/var/www');
    });

    it('renders message, class and trimmed trace as JSON in development', function (): void {
        $report = ErrorReport::fromThrowable(new RuntimeException('Boom'), Severity::Error, new DateTimeImmutable());

        $data = json_decode((new JsonFormatter(webEnvironment(production: false)))->format($report), true);

        expect($data['message'])->toBe('Boom')
            ->and($data['exception'])->toBe(RuntimeException::class)
            ->and($data['file'])->toBe(__FILE__)
            ->and($data['line'])->toBeInt()
            ->and($data['trace'])->toBeArray()
            ->and(count($data['trace']))->toBeLessThanOrEqual(JsonFormatter::MAX_TRACE_FRAMES)
            ->and($data['trace'][0])->toBeString();
    });

    it('renders an HTTP exception response data even in production', function (): void {
        $report = ErrorReport::fromThrowable(new TeapotException('internal detail'), Severity::Error, new DateTimeImmutable());

        $json = (new JsonFormatter(webEnvironment(production: true)))->format($report);

        expect(json_decode($json, true))->toBe(['message' => 'Slow down.']);
    });
});

describe('HttpErrorStatus', function (): void {
    it('uses the status and headers of an HttpExceptionInterface and 500 otherwise', function (): void {
        expect(HttpErrorStatus::statusCode(new TeapotException()))->toBe(429)
            ->and(HttpErrorStatus::headers(new TeapotException()))->toBe(['Retry-After' => '30'])
            ->and(HttpErrorStatus::statusCode(new RuntimeException()))->toBe(500)
            ->and(HttpErrorStatus::headers(new RuntimeException()))->toBeEmpty();
    });
});

describe('SimpleErrorHandler HTTP awareness', function (): void {
    it('uses the status of an HttpExceptionInterface that reaches the handler', function (): void {
        [$handler] = handleWith(webEnvironment(production: true, server: []), new TeapotException());

        expect($handler->statusCodeSet)->toBe(429)
            ->and($handler->headersSent['Retry-After'])->toBe('30');
    });

    it('sends the JSON content type header', function (): void {
        [$handler, $output] = handleWith(webEnvironment(production: true), new RuntimeException('secret'));

        expect($handler->statusCodeSet)->toBe(500)
            ->and($handler->headersSent['Content-Type'])->toBe('application/json')
            ->and(json_decode($output, true))->toBe(['message' => 'Server Error']);
    });

    it('keeps rendering HTML when the client does not accept JSON', function (): void {
        [$handler, $output] = handleWith(
            webEnvironment(production: true, server: ['HTTP_ACCEPT' => 'text/html']),
            new RuntimeException('secret'),
        );

        expect($handler->headersSent['Content-Type'])->toBe('text/html; charset=UTF-8')
            ->and($output)->toContain('<html>')->not->toContain('secret');
    });
});

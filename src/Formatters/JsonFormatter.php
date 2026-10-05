<?php

declare(strict_types=1);

namespace Marko\ErrorsSimple\Formatters;

use JsonException;
use Marko\Core\Exceptions\HttpExceptionInterface;
use Marko\Errors\Contracts\FormatterInterface;
use Marko\Errors\ErrorReport;
use Marko\ErrorsSimple\Environment;

/**
 * JSON error body for API clients.
 *
 * Production: `{"message": "Server Error"}` (or an HTTP exception's own
 * client-safe response data). Development: the message, exception class,
 * location and a trimmed trace.
 */
class JsonFormatter implements FormatterInterface
{
    public const string CONTENT_TYPE = 'application/json';

    public const int MAX_TRACE_FRAMES = 20;

    public function __construct(
        private readonly Environment $environment,
    ) {}

    /**
     * @throws JsonException
     */
    public function format(
        ErrorReport $report,
    ): string {
        $data = $this->environment->isProduction()
            ? $this->productionData($report)
            : $this->developmentData($report);

        return json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function productionData(
        ErrorReport $report,
    ): array {
        if ($report->throwable instanceof HttpExceptionInterface) {
            return ['message' => 'Error', ...$report->throwable->getResponseData()];
        }

        return ['message' => 'Server Error'];
    }

    /**
     * @return array<string, mixed>
     */
    private function developmentData(
        ErrorReport $report,
    ): array {
        $trace = array_map(
            static function (array $frame): string {
                $location = isset($frame['file']) ? $frame['file'] . ':' . ($frame['line'] ?? 0) : '[internal]';
                $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');

                return "$location $call()";
            },
            array_slice($report->trace, 0, self::MAX_TRACE_FRAMES),
        );

        return [
            'message' => $report->message,
            'exception' => $report->throwable::class,
            'file' => $report->file,
            'line' => $report->line,
            'trace' => $trace,
        ];
    }
}

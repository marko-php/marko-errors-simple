<?php

declare(strict_types=1);

namespace Marko\ErrorsSimple;

class Environment
{
    /**
     * @param array<string, string>|null $envVars
     * @param array<string, mixed>|null $server Request server variables; defaults to $_SERVER
     */
    public function __construct(
        private ?string $sapi = null,
        private ?array $envVars = null,
        private ?array $server = null,
    ) {}

    public function isCli(): bool
    {
        return $this->getSapi() === 'cli';
    }

    public function isWeb(): bool
    {
        return !$this->isCli();
    }

    public function isDevelopment(): bool
    {
        return !$this->isProduction();
    }

    public function isProduction(): bool
    {
        $env = $this->getEnvVar('MARKO_ENV') ?? $this->getEnvVar('APP_ENV');
        $envLower = $env !== null ? strtolower($env) : null;

        return in_array($envLower, ['production', 'prod'], true);
    }

    /**
     * Whether the current request asks for a JSON response: an Accept header
     * containing application/json or a +json type, or a JSON Content-Type
     * when no Accept header is sent. Error handlers run outside the router,
     * so this reads the server variables directly.
     */
    public function acceptsJson(): bool
    {
        $server = $this->server ?? $_SERVER;
        $accept = $server['HTTP_ACCEPT'] ?? null;

        if (is_string($accept) && $accept !== '') {
            return $this->isJsonMediaType($accept);
        }

        $contentType = $server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? '';

        return is_string($contentType) && $this->isJsonMediaType($contentType);
    }

    private function isJsonMediaType(
        string $value,
    ): bool {
        $value = strtolower($value);

        return str_contains($value, 'application/json') || str_contains($value, '+json');
    }

    private function getSapi(): string
    {
        return $this->sapi ?? PHP_SAPI;
    }

    private function getEnvVar(
        string $name,
    ): ?string {
        if ($this->envVars !== null && array_key_exists($name, $this->envVars)) {
            return $this->envVars[$name];
        }

        $value = getenv($name);

        return $value === false ? null : $value;
    }
}

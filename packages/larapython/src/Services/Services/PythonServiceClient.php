<?php

declare(strict_types=1);

namespace LaraPython\Services\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class PythonServiceClient
{
    /**
     * Custom headers for outgoing requests.
     *
     * @var array<string, string>
     */
    protected array $headers = [];

    /**
     * Custom request timeout override.
     */
    protected ?int $customTimeout = null;

    public function __construct(
        protected readonly PythonServiceConfig $config
    ) {
    }

    public function config(): PythonServiceConfig
    {
        return $this->config;
    }

    public function baseUrl(): string
    {
        return $this->config->baseUrl();
    }

    /**
     * Attach custom headers to requests.
     *
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = array_merge($this->headers, $headers);

        return $clone;
    }

    /**
     * Override request timeout in seconds.
     */
    public function timeout(int $seconds): self
    {
        $clone = clone $this;
        $clone->customTimeout = $seconds;

        return $clone;
    }

    /**
     * Send a GET request to the service.
     *
     * @param  array<string, mixed>|string|null  $query
     */
    public function get(string $url, array|string|null $query = null): Response
    {
        return $this->buildRequest()->get($this->resolveUrl($url), $query);
    }

    /**
     * Send a POST request to the service.
     *
     * @param  array<string, mixed>  $data
     */
    public function post(string $url, array $data = []): Response
    {
        return $this->buildRequest()->post($this->resolveUrl($url), $data);
    }

    /**
     * Send a PUT request to the service.
     *
     * @param  array<string, mixed>  $data
     */
    public function put(string $url, array $data = []): Response
    {
        return $this->buildRequest()->put($this->resolveUrl($url), $data);
    }

    /**
     * Send a PATCH request to the service.
     *
     * @param  array<string, mixed>  $data
     */
    public function patch(string $url, array $data = []): Response
    {
        return $this->buildRequest()->patch($this->resolveUrl($url), $data);
    }

    /**
     * Send a DELETE request to the service.
     *
     * @param  array<string, mixed>  $data
     */
    public function delete(string $url, array $data = []): Response
    {
        return $this->buildRequest()->delete($this->resolveUrl($url), $data);
    }

    /**
     * Check if the service health endpoint responds with 200 OK.
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->buildRequest(connectTimeout: 1, timeout: 2)
                ->get($this->config->healthUrl());

            return $response->successful();
        } catch (ConnectionException) {
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Build a configured PendingRequest instance.
     */
    protected function buildRequest(?int $connectTimeout = null, ?int $timeout = null): PendingRequest
    {
        $request = Http::baseUrl($this->config->baseUrl())
            ->connectTimeout($connectTimeout ?? $this->config->connectTimeout)
            ->timeout($timeout ?? ($this->customTimeout ?? $this->config->timeout))
            ->acceptJson();

        if (! empty($this->headers)) {
            $request->withHeaders($this->headers);
        }

        return $request;
    }

    /**
     * Ensure URL is properly formatted.
     */
    protected function resolveUrl(string $url): string
    {
        return '/' . ltrim($url, '/');
    }
}


<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Python;
use App\Services\Python\Services\PythonServiceClient;
use App\Services\Python\Services\PythonServiceConfig;
use App\Services\Python\Services\PythonServiceManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class PythonServiceManagerTest extends TestCase
{
    public function test_service_manager_retrieves_registered_service(): void
    {
        /** @var PythonServiceManager $manager */
        $manager = app(PythonServiceManager::class);

        $this->assertTrue($manager->hasService('ai'));
        $this->assertFalse($manager->hasService('unknown_service'));

        $config = $manager->getConfig('ai');
        $this->assertInstanceOf(PythonServiceConfig::class, $config);
        $this->assertSame('ai', $config->key);
        $this->assertSame('http://127.0.0.1:8001', $config->baseUrl());
        $this->assertSame('http://127.0.0.1:8001/health', $config->healthUrl());
    }

    public function test_service_manager_throws_exception_for_unregistered_service(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Python service [missing] is not configured');

        Python::service('missing');
    }

    public function test_facade_returns_service_client(): void
    {
        $client = Python::service('ai');

        $this->assertInstanceOf(PythonServiceClient::class, $client);
        $this->assertSame('http://127.0.0.1:8001', $client->baseUrl());
    }

    public function test_service_client_performs_successful_health_check(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/health' => Http::response([
                'status' => 'healthy',
                'service' => 'ai',
                'version' => '1.0.0',
            ], 200),
        ]);

        $this->assertTrue(Python::service('ai')->healthCheck());

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://127.0.0.1:8001/health'
                && $request->method() === 'GET';
        });
    }

    public function test_service_client_returns_false_on_failed_health_check(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/health' => Http::failedConnection(),
        ]);

        $this->assertFalse(Python::service('ai')->healthCheck());
    }

    public function test_service_client_sends_post_request_with_arabic_payload(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/analyze' => Http::response([
                'status' => 'success',
                'received_text' => 'خدمة الذكاء الاصطناعي ممتازة وسريعة',
                'sentiment' => 'positive',
                'words_count' => 6,
            ], 200),
        ]);

        $response = Python::service('ai')->post('/analyze', [
            'text' => 'خدمة الذكاء الاصطناعي ممتازة وسريعة',
        ]);

        $this->assertTrue($response->successful());
        $this->assertSame('positive', $response->json('sentiment'));
        $this->assertSame(6, $response->json('words_count'));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://127.0.0.1:8001/analyze'
                && $request->data() === ['text' => 'خدمة الذكاء الاصطناعي ممتازة وسريعة']
                && $request->header('Accept')[0] === 'application/json';
        });
    }

    public function test_service_client_supports_custom_headers_and_timeout(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/custom' => Http::response(['ok' => true], 200),
        ]);

        $client = Python::service('ai')
            ->withHeaders(['X-Client-Id' => 'Laravel-Engine'])
            ->timeout(15);

        $response = $client->get('/custom');

        $this->assertTrue($response->successful());

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://127.0.0.1:8001/custom'
                && $request->hasHeader('X-Client-Id', 'Laravel-Engine');
        });
    }

    public function test_service_manager_reports_status_summary(): void
    {
        /** @var PythonServiceManager $manager */
        $manager = app(PythonServiceManager::class);

        $status = $manager->status('ai');

        $this->assertSame('ai', $status['key']);
        $this->assertSame('AI Microservice', $status['name']);
        $this->assertFalse($status['running']);
        $this->assertFalse($status['healthy']);
        $this->assertSame('http://127.0.0.1:8001', $status['url']);
        $this->assertSame(8001, $status['port']);
    }
}


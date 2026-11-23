<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Tools\PythonEnvironment;
use Laravel\Boost\Mcp\ToolRegistry;
use Laravel\Mcp\Request;
use Tests\TestCase;

class PythonEnvironmentToolTest extends TestCase
{
    public function test_python_environment_tool_is_registered_in_boost_tool_registry(): void
    {
        $availableTools = ToolRegistry::getAvailableTools();

        $this->assertContains(PythonEnvironment::class, $availableTools);
        $this->assertTrue(ToolRegistry::isToolAllowed(PythonEnvironment::class));
    }

    public function test_python_environment_tool_inspects_runtime(): void
    {
        /** @var PythonEnvironment $tool */
        $tool = app(PythonEnvironment::class);

        $response = $tool->handle(new Request(['check_packages' => false]));

        $this->assertFalse($response->isError());

        $data = json_decode((string) $response->content(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('installed', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('executable', $data);
        $this->assertArrayHasKey('virtual_environment', $data);
    }

    public function test_python_environment_tool_reflects_configured_executable_path(): void
    {
        config(['python.executable_paths.python' => '/opt/custom/python3']);

        /** @var PythonEnvironment $tool */
        $tool = app(PythonEnvironment::class);

        $response = $tool->handle(new Request(['check_packages' => false]));
        $data = json_decode((string) $response->content(), true);

        $this->assertSame('/opt/custom/python3', $data['executable']);
    }
}

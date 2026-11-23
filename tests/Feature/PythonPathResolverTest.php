<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Python\PythonPathResolver;
use Tests\TestCase;

class PythonPathResolverTest extends TestCase
{
    public function test_resolves_default_python_binary_from_configuration(): void
    {
        $resolver = app(PythonPathResolver::class);

        $this->assertSame('python3', $resolver->pythonBinary());
    }

    public function test_resolves_custom_configured_python_binary(): void
    {
        config(['python.executable_paths.python' => '/custom/bin/python']);

        $resolver = app(PythonPathResolver::class);

        $this->assertSame('/custom/bin/python', $resolver->pythonBinary());
    }

    public function test_resolves_default_virtual_environment_path(): void
    {
        $resolver = app(PythonPathResolver::class);

        $this->assertSame(base_path('.venv'), $resolver->virtualEnvironmentPath());
    }

    public function test_resolves_custom_virtual_environment_path(): void
    {
        config(['python.executable_paths.venv' => '/tmp/custom-venv']);

        $resolver = app(PythonPathResolver::class);

        $this->assertSame('/tmp/custom-venv', $resolver->virtualEnvironmentPath());
        $this->assertFalse($resolver->hasVirtualEnvironment());
    }

    public function test_detects_python_version_and_availability(): void
    {
        $resolver = app(PythonPathResolver::class);

        $this->assertTrue($resolver->isAvailable());
        $this->assertNotNull($resolver->version());
        $this->assertStringStartsWith('Python', (string) $resolver->version());
    }
}


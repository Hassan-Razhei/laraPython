<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Python;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PythonArtisanCommandsTest extends TestCase
{
    protected string $testScript = 'test_sample_command.py';

    protected function tearDown(): void
    {
        $target = base_path('python/scripts/'.$this->testScript);
        if (file_exists($target)) {
            File::delete($target);
        }

        parent::tearDown();
    }

    public function test_python_health_command_displays_report(): void
    {
        $this->artisan('python:health')
            ->expectsOutputToContain('Python Integration Engine :: Health Report')
            ->expectsOutputToContain('Python Runtime')
            ->assertSuccessful();
    }

    public function test_make_python_script_creates_executable_script(): void
    {
        $this->artisan('make:python-script', ['name' => $this->testScript])
            ->expectsOutputToContain('Python script created successfully')
            ->assertSuccessful();

        $target = base_path('python/scripts/'.$this->testScript);
        $this->assertFileExists($target);

        // Execute newly created script via Python facade
        $result = Python::run($this->testScript, ['item' => 'keyboard']);
        $this->assertTrue($result->successful());
        $this->assertSame('success', $result->json('status'));
        $this->assertSame('keyboard', $result->json('received.item'));
    }

    public function test_make_python_script_prevents_path_traversal(): void
    {
        $this->artisan('make:python-script', ['name' => '../../malicious_script'])
            ->expectsOutputToContain('path traversal is not permitted')
            ->assertFailed();
    }

    public function test_make_python_script_prevents_duplicate_without_force(): void
    {
        $this->artisan('make:python-script', ['name' => $this->testScript])
            ->assertSuccessful();

        $this->artisan('make:python-script', ['name' => $this->testScript])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        $this->artisan('make:python-script', ['name' => $this->testScript, '--force' => true])
            ->assertSuccessful();
    }
}


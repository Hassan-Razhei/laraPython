<?php

declare(strict_types=1);

namespace App\Console\Commands\Python;

use App\Services\Python\PythonPathResolver;
use App\Services\Python\PythonScriptSecurity;
use Illuminate\Console\Command;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

class PythonHealthCommand extends Command
{
    protected $signature = 'python:health';

    protected $description = 'Inspect and report the health of the Python runtime and virtual environment';

    public function handle(
        PythonPathResolver $pathResolver,
        PythonScriptSecurity $scriptSecurity
    ): int {
        $isAvailable = $pathResolver->isAvailable();
        $version = $pathResolver->version();
        $binary = $pathResolver->pythonBinary();
        $pip = $pathResolver->pipBinary();
        $hasVenv = $pathResolver->hasVirtualEnvironment();
        $venvPath = $pathResolver->virtualEnvironmentPath();
        $scriptsDir = $scriptSecurity->scriptsDirectory();

        $scriptCount = 0;
        if (is_dir($scriptsDir)) {
            $files = glob($scriptsDir.DIRECTORY_SEPARATOR.'*.py');
            $scriptCount = is_array($files) ? count($files) : 0;
        }

        $rows = [
            ['Python Runtime', $isAvailable ? '✓ Ready' : '✗ Missing', $version ?? 'Not found'],
            ['Active Binary', $isAvailable ? '✓ Active' : '✗ Unresolved', $binary],
            ['Pip Package Manager', $pip !== null ? '✓ Available' : '⚠ Missing', $pip ?? 'pip not found in PATH or .venv'],
            ['Virtual Environment', $hasVenv ? '✓ Detected' : '⚠ Not Created', $venvPath],
            ['Scripts Directory', is_dir($scriptsDir) ? '✓ Ready' : '⚠ Missing', "{$scriptsDir} ({$scriptCount} scripts)"],
        ];

        $this->newLine();
        info('Python Integration Engine :: Health Report');
        table(['Component', 'Status', 'Details'], $rows);

        if (! $isAvailable) {
            error('Python 3 is not available on your system or the configured path is invalid.');

            return self::FAILURE;
        }

        if (! $hasVenv) {
            $this->comment('Tip: Run "php artisan python:install" to generate an isolated virtual environment.');
        }

        return self::SUCCESS;
    }
}


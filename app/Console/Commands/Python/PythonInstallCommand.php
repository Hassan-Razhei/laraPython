<?php

declare(strict_types=1);

namespace App\Console\Commands\Python;

use App\Services\Python\PythonPathResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class PythonInstallCommand extends Command
{
    protected $signature = 'python:install {--force : Force recreation of the virtual environment}';

    protected $description = 'Set up the local Python virtual environment and initialize dependencies';

    public function handle(PythonPathResolver $pathResolver): int
    {
        $venvPath = $pathResolver->virtualEnvironmentPath();

        if (! $pathResolver->isAvailable()) {
            error('Python 3 is not detected on your system. Please install Python 3 before running this command.');

            return self::FAILURE;
        }

        if (is_dir($venvPath) && ! $this->option('force')) {
            warning("Virtual environment already exists at [{$venvPath}].");
            $this->comment('Use --force to recreate the virtual environment.');

            return self::SUCCESS;
        }

        if (is_dir($venvPath) && $this->option('force')) {
            $this->info("Removing existing virtual environment at [{$venvPath}]...");
            File::deleteDirectory($venvPath);
        }

        $this->info("Creating Python virtual environment at [{$venvPath}]...");

        $result = Process::timeout(120)->run(['python3', '-m', 'venv', $venvPath]);

        if (! $result->successful()) {
            File::deleteDirectory($venvPath);
            $errorDetails = trim($result->errorOutput() !== '' ? $result->errorOutput() : $result->output());
            error("Failed to create virtual environment: {$errorDetails}");

            if (str_contains($errorDetails, 'python3-venv') || str_contains($errorDetails, 'ensurepip')) {
                $this->comment('Tip: On Debian/Ubuntu systems, install the venv package using:');
                $this->line('  sudo apt-get install python3-venv (or python3.13-venv)');
            }

            return self::FAILURE;
        }

        // Ensure requirements.txt exists
        $requirementsFile = base_path('requirements.txt');
        if (! file_exists($requirementsFile)) {
            File::put($requirementsFile, "# Add your Python project dependencies here\n");
            $this->info('Created starter requirements.txt file.');
        }

        info("Python virtual environment initialized successfully at [{$venvPath}].");

        return self::SUCCESS;
    }
}

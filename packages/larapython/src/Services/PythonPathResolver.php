<?php

declare(strict_types=1);

namespace LaraPython\Services;

use Illuminate\Support\Facades\Process;

class PythonPathResolver
{
    /**
     * Resolve the active Python executable path.
     *
     * Prioritizes the project's virtual environment binary if present,
     * falling back to the configured or system executable path.
     */
    public function pythonBinary(): string
    {
        $venvBinary = $this->virtualEnvironmentBinary('python');

        if ($venvBinary !== null && file_exists($venvBinary)) {
            return $venvBinary;
        }

        return (string) config('python.executable_paths.python', 'python3');
    }

    /**
     * Resolve the active pip binary path.
     */
    public function pipBinary(): ?string
    {
        $venvPip = $this->virtualEnvironmentBinary('pip');

        if ($venvPip !== null && file_exists($venvPip)) {
            return $venvPip;
        }

        $systemPipCheck = Process::run(['which', 'pip3']);

        if ($systemPipCheck->successful() && trim($systemPipCheck->output()) !== '') {
            return trim($systemPipCheck->output());
        }

        return null;
    }

    /**
     * Get the configured virtual environment root directory.
     */
    public function virtualEnvironmentPath(): string
    {
        return (string) config('python.executable_paths.venv', base_path('.venv'));
    }

    /**
     * Check whether the configured virtual environment directory exists.
     */
    public function hasVirtualEnvironment(): bool
    {
        return is_dir($this->virtualEnvironmentPath());
    }

    /**
     * Inspect and return the Python version string.
     */
    public function version(): ?string
    {
        $result = Process::run([$this->pythonBinary(), '--version']);

        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->output());

        return $output !== '' ? $output : null;
    }

    /**
     * Check whether the Python runtime is available and executable.
     */
    public function isAvailable(): bool
    {
        return $this->version() !== null;
    }

    /**
     * Resolve the absolute path to a binary inside the virtual environment.
     */
    protected function virtualEnvironmentBinary(string $binary): ?string
    {
        $venv = $this->virtualEnvironmentPath();

        if (! is_dir($venv)) {
            return null;
        }

        // Support both POSIX (bin/) and Windows (Scripts/) directory conventions
        $posixPath = $venv.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.$binary;
        if (file_exists($posixPath)) {
            return $posixPath;
        }

        $windowsPath = $venv.DIRECTORY_SEPARATOR.'Scripts'.DIRECTORY_SEPARATOR.$binary.'.exe';
        if (file_exists($windowsPath)) {
            return $windowsPath;
        }

        return null;
    }
}


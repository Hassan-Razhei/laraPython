<?php

declare(strict_types=1);

namespace LaraPython\Console\Commands;

use LaraPython\Services\PythonScriptSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class MakePythonScriptCommand extends Command
{
    protected $signature = 'make:python-script {name : The name of the Python script} {--force : Overwrite if the script already exists}';

    protected $description = 'Create a new structured Python script with the larabridge template';

    public function handle(PythonScriptSecurity $scriptSecurity): int
    {
        $name = trim((string) $this->argument('name'));

        // Prevent path traversal
        if (str_contains($name, '..') || str_contains($name, "\0")) {
            error('Invalid script name: path traversal is not permitted.');

            return self::FAILURE;
        }

        if (! str_ends_with(strtolower($name), '.py')) {
            $name .= '.py';
        }

        $scriptsDir = $scriptSecurity->scriptsDirectory();
        File::ensureDirectoryExists($scriptsDir);

        $targetPath = $scriptsDir.DIRECTORY_SEPARATOR.$name;

        if (file_exists($targetPath) && ! $this->option('force')) {
            error("Script file already exists at [{$targetPath}]. Use --force to overwrite.");

            return self::FAILURE;
        }

        $template = $this->stubTemplate();

        File::put($targetPath, $template);

        info("Python script created successfully at [{$targetPath}].");

        return self::SUCCESS;
    }

    /**
     * Get the standardized script template stub.
     */
    protected function stubTemplate(): string
    {
        return <<<'PYTHON'
import sys
import larabridge

def main():
    # 1. Retrieve structured JSON payload sent from Laravel
    payload = larabridge.payload()

    # TODO: Implement your Python logic here

    # 2. Return structured JSON response back to Laravel
    larabridge.respond({
        "status": "success",
        "message": "Script executed successfully",
        "received": payload,
    })

if __name__ == "__main__":
    main()

PYTHON;
    }
}


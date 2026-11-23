<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Process;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class PythonEnvironment extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Inspect the Python runtime environment, interpreter path, version, and virtual environment status.';

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'check_packages' => $schema->boolean()
                ->description('Whether to list installed packages in the virtual environment or global Python.'),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $checkPackages = (bool) $request->get('check_packages', false);

        $versionResult = Process::run(['python3', '--version']);
        $installed = $versionResult->successful();
        $version = trim($versionResult->output());

        $whichResult = Process::run(['which', 'python3']);
        $executable = trim($whichResult->output());

        $venvPath = base_path('.venv');
        $venvDetected = is_dir($venvPath);

        $packages = [];
        if ($checkPackages && $installed) {
            $pipCmd = $venvDetected && file_exists($venvPath.'/bin/pip')
                ? [$venvPath.'/bin/pip', 'list', '--format=json']
                : ['python3', '-m', 'pip', 'list', '--format=json'];

            $pipResult = Process::run($pipCmd);
            if ($pipResult->successful()) {
                $packages = json_decode($pipResult->output(), true) ?? [];
            }
        }

        return Response::json([
            'installed' => $installed,
            'version' => $version !== '' ? $version : null,
            'executable' => $executable !== '' ? $executable : null,
            'virtual_environment' => [
                'detected' => $venvDetected,
                'path' => $venvDetected ? $venvPath : null,
            ],
            'packages_count' => count($packages),
            'packages' => $packages,
        ]);
    }
}


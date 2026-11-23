<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Python\PythonPathResolver;
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

    public function __construct(
        protected PythonPathResolver $pathResolver
    ) {
    }

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

        $installed = $this->pathResolver->isAvailable();
        $version = $this->pathResolver->version();
        $executable = $this->pathResolver->pythonBinary();
        $hasVenv = $this->pathResolver->hasVirtualEnvironment();
        $venvPath = $this->pathResolver->virtualEnvironmentPath();

        $packages = [];

        if ($checkPackages && $installed) {
            $packages = $this->resolveInstalledPackages();
        }

        return Response::json([
            'installed' => $installed,
            'version' => $version,
            'executable' => $executable,
            'virtual_environment' => [
                'detected' => $hasVenv,
                'path' => $hasVenv ? $venvPath : null,
            ],
            'packages_count' => count($packages),
            'packages' => $packages,
        ]);
    }

    /**
     * Retrieve list of installed packages.
     *
     * @return array<int, array{name: string, version: string}>
     */
    protected function resolveInstalledPackages(): array
    {
        $pip = $this->pathResolver->pipBinary();

        $command = $pip !== null
            ? [$pip, 'list', '--format=json']
            : [$this->pathResolver->pythonBinary(), '-m', 'pip', 'list', '--format=json'];

        $result = Process::run($command);

        if (! $result->successful()) {
            return [];
        }

        $decoded = json_decode($result->output(), true);

        return is_array($decoded) ? $decoded : [];
    }
}

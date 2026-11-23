<?php

declare(strict_types=1);

namespace App\Console\Commands\Python;

use App\Services\Python\Services\PythonServiceManager;
use Illuminate\Console\Command;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class PythonServeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'python:serve
                            {service=ai : The name of the Python service (default: ai)}
                            {--status : Check the status of the service}
                            {--all : Check or manage all registered services}
                            {--stop : Stop the running service}
                            {--restart : Restart the service}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start, stop, or inspect persistent Python microservices (FastAPI / Flask)';

    public function handle(PythonServiceManager $serviceManager): int
    {
        $serviceName = (string) $this->argument('service');

        // Check if all services requested
        if ($this->option('all') || ($this->option('status') && ! $this->hasArgumentValue('service'))) {
            return $this->showAllServicesStatus($serviceManager);
        }

        if (! $serviceManager->hasService($serviceName)) {
            error("Python service [{$serviceName}] is not registered in config/python.php.");

            return self::FAILURE;
        }

        if ($this->option('status')) {
            return $this->showSingleServiceStatus($serviceManager, $serviceName);
        }

        if ($this->option('stop')) {
            return $this->stopService($serviceManager, $serviceName);
        }

        if ($this->option('restart')) {
            info("Restarting Python service [{$serviceName}]...");
            $serviceManager->stop($serviceName);
            sleep(1);

            return $this->startService($serviceManager, $serviceName);
        }

        return $this->startService($serviceManager, $serviceName);
    }

    /**
     * Start the service.
     */
    protected function startService(PythonServiceManager $serviceManager, string $serviceName): int
    {
        $config = $serviceManager->getConfig($serviceName);
        info("Starting Python service [{$config->name}] on {$config->baseUrl()}...");

        $result = $serviceManager->start($serviceName);

        if ($result['status'] === 'already_running') {
            warning($result['message']);
            note("Running on PID: {$result['pid']} | URL: {$config->baseUrl()}");

            return self::SUCCESS;
        }

        if ($result['healthy']) {
            info("✓ {$result['message']}");
            note("PID: {$result['pid']} | Health Check: PASSED ({$config->healthUrl()})");

            return self::SUCCESS;
        }

        warning("! {$result['message']}");
        note("You can verify logs or run with --status to check when it becomes healthy.");

        return self::SUCCESS;
    }

    /**
     * Stop the service.
     */
    protected function stopService(PythonServiceManager $serviceManager, string $serviceName): int
    {
        if (! $serviceManager->isRunning($serviceName)) {
            warning("Service [{$serviceName}] is not currently running.");

            return self::SUCCESS;
        }

        info("Stopping Python service [{$serviceName}]...");
        $stopped = $serviceManager->stop($serviceName);

        if ($stopped) {
            info("✓ Service [{$serviceName}] has been stopped.");

            return self::SUCCESS;
        }

        error("Failed to cleanly stop service [{$serviceName}].");

        return self::FAILURE;
    }

    /**
     * Show single service status.
     */
    protected function showSingleServiceStatus(PythonServiceManager $serviceManager, string $serviceName): int
    {
        $status = $serviceManager->status($serviceName);

        table(
            headers: ['Attribute', 'Value'],
            rows: [
                ['Service Key', $status['key']],
                ['Service Name', $status['name']],
                ['Process State', $status['running'] ? '🟢 Running' : '🔴 Stopped'],
                ['Health Check', $status['healthy'] ? '🟢 Healthy' : ($status['running'] ? '🟡 Unhealthy / Booting' : '⚪ Inactive')],
                ['PID', (string) ($status['pid'] ?? 'N/A')],
                ['Base URL', $status['url']],
                ['Port', (string) $status['port']],
                ['Script Path', $status['script']],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Show all services status.
     */
    protected function showAllServicesStatus(PythonServiceManager $serviceManager): int
    {
        $services = $serviceManager->allServices();

        if (empty($services)) {
            warning('No Python services are configured.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($services as $key => $config) {
            $status = $serviceManager->status($key);
            $rows[] = [
                $status['key'],
                $status['name'],
                $status['running'] ? '🟢 Running' : '🔴 Stopped',
                $status['healthy'] ? '🟢 Healthy' : ($status['running'] ? '🟡 Unhealthy' : '⚪ Inactive'),
                (string) ($status['pid'] ?? 'N/A'),
                $status['url'],
            ];
        }

        table(
            headers: ['Service', 'Name', 'Process', 'Health', 'PID', 'URL'],
            rows: $rows
        );

        return self::SUCCESS;
    }

    /**
     * Check if argument was explicitly passed.
     */
    protected function hasArgumentValue(string $key): bool
    {
        return $this->hasArgument($key) && $this->argument($key) !== 'ai';
    }
}


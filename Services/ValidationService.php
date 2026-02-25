<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Services;

use App\Enums\WorkerStatus;
use App\Models\Site;
use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Support\EnvParser;
use Throwable;

class ValidationService
{
    public function __construct(private readonly EnvParser $envParser) {}

    /**
     * @return array<string, mixed>
     */
    public function run(Site $targetSite, ?string $healthcheckUrl = null): array
    {
        $checks = [
            $this->checkHttp($targetSite, $healthcheckUrl),
            $this->checkArtisan($targetSite),
            $this->checkDatabase($targetSite),
            $this->checkRedis($targetSite),
            $this->checkWorkers($targetSite),
            $this->checkHorizon($targetSite),
        ];

        $passed = collect($checks)->where('ok', true)->count();
        $failed = collect($checks)->where('ok', false)->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'target_site_id' => $targetSite->id,
            'checks' => $checks,
            'summary' => [
                'total' => count($checks),
                'passed' => $passed,
                'failed' => $failed,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkHttp(Site $site, ?string $healthcheckUrl): array
    {
        $pathOrUrl = trim((string) $healthcheckUrl);
        if ($pathOrUrl === '') {
            $pathOrUrl = '/';
        }

        try {
            if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
                $url = $pathOrUrl;
                $command = 'curl -k -s -o /dev/null -w "%{http_code}" '.escapeshellarg($url);
            } else {
                $path = '/'.ltrim($pathOrUrl, '/');
                $command = sprintf(
                    'curl -k -s -o /dev/null -w "%%{http_code}" -H %s %s',
                    escapeshellarg('Host: '.$site->domain),
                    escapeshellarg('http://127.0.0.1'.$path),
                );
            }

            $statusCode = trim($site->server->ssh()->exec($command, 'site-mover-validate-http', $site->id));
            $statusInt = (int) $statusCode;

            return [
                'name' => 'http',
                'ok' => $statusInt >= 200 && $statusInt < 500,
                'details' => [
                    'status_code' => $statusInt,
                    'target' => $pathOrUrl,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->failedCheck('http', $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkArtisan(Site $site): array
    {
        try {
            $command = 'cd '.escapeshellarg($site->path).' && php artisan --version';
            $output = trim($site->server->ssh($site->user)->exec($command, 'site-mover-validate-artisan', $site->id));

            return [
                'name' => 'artisan',
                'ok' => str_contains(strtolower($output), 'laravel'),
                'details' => [
                    'output' => $output,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->failedCheck('artisan', $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDatabase(Site $site): array
    {
        try {
            $command = 'cd '.escapeshellarg($site->path).' && php artisan migrate:status --no-interaction --no-ansi';
            $output = $site->server->ssh($site->user)->exec($command, 'site-mover-validate-db', $site->id);

            return [
                'name' => 'database',
                'ok' => trim($output) !== '',
                'details' => [
                    'output' => $this->truncate($output),
                ],
            ];
        } catch (Throwable $exception) {
            return $this->failedCheck('database', $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRedis(Site $site): array
    {
        $env = $this->envParser->parse($site->getEnv());
        $redisUsed =
            ($env['QUEUE_CONNECTION'] ?? '') === 'redis' ||
            ($env['CACHE_STORE'] ?? '') === 'redis' ||
            ($env['REDIS_HOST'] ?? '') !== '';

        if (! $redisUsed) {
            return [
                'name' => 'redis',
                'ok' => true,
                'details' => [
                    'skipped' => true,
                    'reason' => 'Redis not configured in environment',
                ],
            ];
        }

        $host = $env['REDIS_HOST'] ?? '127.0.0.1';
        $port = $env['REDIS_PORT'] ?? '6379';
        $password = $env['REDIS_PASSWORD'] ?? '';

        try {
            $command = 'redis-cli -h '.escapeshellarg($host).' -p '.escapeshellarg($port);
            if ($password !== '') {
                $command .= ' -a '.escapeshellarg($password);
            }
            $command .= ' ping';

            $output = trim($site->server->ssh()->exec($command, 'site-mover-validate-redis', $site->id));
            $ok = str_contains(strtoupper($output), 'PONG');

            return [
                'name' => 'redis',
                'ok' => $ok,
                'details' => [
                    'output' => $output,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->failedCheck('redis', $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkWorkers(Site $site): array
    {
        $workers = $site->workers()->get();
        $running = $workers->where('status', WorkerStatus::RUNNING)->count();

        return [
            'name' => 'workers',
            'ok' => $workers->count() === 0 || $running > 0,
            'details' => [
                'total' => $workers->count(),
                'running' => $running,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkHorizon(Site $site): array
    {
        $hasHorizonWorker = $site->workers()
            ->get()
            ->contains(fn ($worker): bool => str_contains(strtolower($worker->command), 'horizon'));

        if (! $hasHorizonWorker) {
            return [
                'name' => 'horizon',
                'ok' => true,
                'details' => [
                    'skipped' => true,
                    'reason' => 'No horizon worker commands detected',
                ],
            ];
        }

        try {
            $command = 'cd '.escapeshellarg($site->path).' && php artisan horizon:status --no-ansi';
            $output = trim($site->server->ssh($site->user)->exec($command, 'site-mover-validate-horizon', $site->id));
            $ok = str_contains(strtolower($output), 'running');

            return [
                'name' => 'horizon',
                'ok' => $ok,
                'details' => [
                    'output' => $output,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->failedCheck('horizon', $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function failedCheck(string $name, string $error): array
    {
        return [
            'name' => $name,
            'ok' => false,
            'details' => [
                'error' => $error,
            ],
        ];
    }

    private function truncate(string $value, int $limit = 500): string
    {
        $value = trim($value);
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).'...';
    }
}

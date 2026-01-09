<?php

namespace LaravelRouteRetry\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Http\Kernel;
use LaravelRouteRetry\Events\RetryFailed;
use LaravelRouteRetry\Events\RetrySucceeded;
use LaravelRouteRetry\Models\RequestRetry;

class ProcessRetries extends Command
{
    protected $signature = 'retry:process {--id=* : Specific retry IDs} {--tag= : Filter by tag} {--fingerprint= : Filter by fingerprint}';
    protected $description = 'Process pending request retries';

    public function handle()
    {
        $ids = $this->option('id');
        $tag = $this->option('tag');
        $fingerprint = $this->option('fingerprint');

        $query = RequestRetry::pending()->due();

        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        }

        if ($tag) {
            // Use whereJsonContains if possibly supported, or fallback to LIKE
            $query->where(function ($q) use ($tag) {
                $q->whereJsonContains('tags', $tag)
                  ->orWhere('tags', 'like', '%"' . $tag . '"%');
            });
        }

        if ($fingerprint) {
            $query->where('fingerprint', $fingerprint);
        }

        $retries = $query->get();

        if ($retries->isEmpty()) {
            $this->info("No pending retries found (Tag: " . ($tag ?: 'none') . ", IDs: " . implode(',', $ids) . ").");
            return self::SUCCESS;
        }

        $this->info("Found " . $retries->count() . " retries to process.");

        foreach ($retries as $retry) {
            $this->info("Processing retry ID: {$retry->id}");
            try {
                $this->processRetry($retry);
            } catch (\Exception $e) {
                Log::error("Retry ID: {$retry->id} failed with exception: " . $e->getMessage());
                $this->error("Retry ID: {$retry->id} failed.");
                $this->markAttempt($retry, 500, $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    protected function processRetry(RequestRetry $retry)
    {
        $files = $this->reconstructFiles($retry->files);
        $parameters = $retry->body ?? [];
        $headers = $retry->headers ?? [];
        $server = $this->transformHeadersToServerVars($headers);

        $request = Request::create(
            '/' . ltrim($retry->uri, '/'),
            $retry->method,
            $parameters,
            [],
            $files,
            $server
        );

        $request->headers->set('X-Retry-Attempt', $retry->id);

        $kernel = app()->make(Kernel::class);
        $response = $kernel->handle($request);
        
        $status = $response->getStatusCode();
        $this->info("Retry ID: {$retry->id} Response: {$status}");

        if ($status >= 200 && $status < 300) {
            $this->markSuccess($retry);
        } elseif ($status >= 500) {
            $this->markAttempt($retry, $status);
        } else {
            $this->markFailed($retry, 'completed_with_error', "Response status: {$status}");
        }
    }

    protected function markSuccess(RequestRetry $retry)
    {
        $retry->update([
            'status' => 'completed',
        ]);

        $this->cleanupFiles($retry->files);

        RetrySucceeded::dispatch($retry);
    }

    protected function markAttempt(RequestRetry $retry, int $status, ?string $reason = null)
    {
        $retriesCount = $retry->retries_count + 1;
        
        if ($retriesCount >= $retry->max_retries) {
             $this->markFailed($retry, 'failed', $reason ?? "Max retries reached with status {$status}");
        } else {
            $retry->update([
                'retries_count' => $retriesCount,
                'next_attempt_at' => now()->addSeconds($retry->retry_delay),
            ]);
        }
    }

    protected function markFailed(RequestRetry $retry, string $statusStr, string $reason)
    {
         $retry->update([
            'status' => $statusStr,
        ]);
        
         $this->cleanupFiles($retry->files);

         RetryFailed::dispatch($retry, $reason);
    }

    protected function cleanupFiles(?array $storedFiles)
    {
        if (!$storedFiles) return;
        foreach ($storedFiles as $data) {
            if (isset($data['path'])) {
                Storage::delete($data['path']);
            } elseif (is_array($data)) {
                $this->cleanupFiles($data);
            }
        }
    }

    protected function reconstructFiles(?array $storedFiles)
    {
        if (!$storedFiles) return [];
        $files = [];
        foreach ($storedFiles as $key => $data) {
            if (isset($data['path'])) {
                $absolutePath = Storage::path($data['path']);
                if (file_exists($absolutePath)) {
                    $files[$key] = new UploadedFile(
                        $absolutePath,
                        $data['original_name'],
                        $data['mime_type'],
                        null,
                        true
                    );
                }
            } else {
                $files[$key] = $this->reconstructFiles($data);
            }
        }
        return $files;
    }

    protected function transformHeadersToServerVars(array $headers)
    {
        $server = [];
        foreach ($headers as $key => $values) {
            $value = is_array($values) ? $values[0] : $values;
            $verifyKey = strtoupper(str_replace('-', '_', $key));
            if (in_array($verifyKey, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $server[$verifyKey] = $value;
            } else {
                $server['HTTP_' . $verifyKey] = $value;
            }
        }
        return $server;
    }
}

<?php

namespace LaravelRouteRetry\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use LaravelRouteRetry\Events\RequestCaptured;
use LaravelRouteRetry\Models\RequestRetry;
use Symfony\Component\HttpFoundation\Response;

class CaptureRetryableRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Avoid infinite loops if this is a retry attempt
        if ($request->headers->has('X-Retry-Attempt')) {
            return $next($request);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 500 && $response->getStatusCode() < 600) {
            try {
                $this->captureRequest($request);
            } catch (\Exception $e) {
                Log::error('Failed to capture retryable request: ' . $e->getMessage());
            }
        }

        return $response;
    }

    protected function captureRequest(Request $request)
    {
        $fingerprint = $this->requestFingerprint($request);

        // Check if there's already a pending retry for this fingerprint to avoid duplicates
        $exists = RequestRetry::pending()
            ->where('fingerprint', $fingerprint)
            ->exists();

        if ($exists) {
            return;
        }

        $storedFiles = $this->handleFiles($request->allFiles());
        $delay = config('retry.delay', 0);
        $intelligence = $this->resolveRouteIntelligence($request);

        $retry = RequestRetry::create([
            'fingerprint' => $fingerprint,
            'method' => $request->method(),
            'uri' => $request->path(),
            'headers' => $request->headers->all(),
            'body' => $request->input(),
            'files' => $storedFiles,
            'tags' => $intelligence['tags'],
            'retries_count' => 0,
            'max_retries' => $intelligence['max_retries'],
            'retry_delay' => $delay,
            'next_attempt_at' => now(),
            'status' => 'pending',
        ]);

        RequestCaptured::dispatch($retry);
    }

    protected function handleFiles(array $files)
    {
        $stored = [];
        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $stored[$key] = $this->handleFiles($file);
            } elseif ($file instanceof UploadedFile) {
                $path = $file->store('retry_temp');
                $stored[$key] = [
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                ];
            }
        }
        return $stored;
    }

    protected function resolveRouteIntelligence(Request $request): array
    {
        $route = $request->route();

        if (!$route) {
            return [
                'tags' => [],
                'max_retries' => config('retry.max_retries', 3),
            ];
        }

        $action = $route->getAction();

        return [
            'tags' => !empty($action['tags'])
                ? $action['tags']
                : array_filter([$route->getName()]),

            'max_retries' => $action['retry']
                ?? config('retry.max_retries', 3),
        ];
    }

    protected function requestFingerprint(Request $request): string
    {
        return sha1(
            $request->method() .
            $request->path() .
            json_encode($request->input())
        );
    }
}

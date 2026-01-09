<?php

namespace LaravelRouteRetry\Tests\Feature;

use LaravelRouteRetry\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

class RetrySystemTest extends TestCase
{
    public static $shouldFail = true;

    protected function setUp(): void
    {
        parent::setUp();
        self::$shouldFail = true;
    }

    public function test_it_captures_failed_request_and_retries_successfully()
    {
        Route::post('/test-retry-cycle', function () {
            if (self::$shouldFail) {
                abort(500);
            }
            return response('success', 200);
        })->retry(3);

        $response = $this->post('/test-retry-cycle', ['data' => 'test']);
        $response->assertStatus(500);

        $this->assertDatabaseHas('request_retries', [
            'uri' => 'test-retry-cycle',
            'status' => 'pending',
            'retries_count' => 0,
        ]);

        self::$shouldFail = false;
        
        $this->artisan('retry:process')
             ->assertExitCode(0);

        $this->assertDatabaseHas('request_retries', [
            'uri' => 'test-retry-cycle',
            'status' => 'completed',
        ]);
    }

    public function test_it_filters_by_tags_ids_and_fingerprints()
    {
        Route::post('/retry-tag-a', function () { return abort(500); })->retry(3)->tags(['tag-a']);
        Route::post('/retry-tag-b', function () { return abort(500); })->retry(3)->tags(['tag-b']);

        $this->post('/retry-tag-a', ['param' => '1']);
        $this->post('/retry-tag-b', ['param' => '2']);

        $idA = DB::table('request_retries')->where('uri', 'retry-tag-a')->value('id');
        $idB = DB::table('request_retries')->where('uri', 'retry-tag-b')->value('id');
        $fingerA = DB::table('request_retries')->where('uri', 'retry-tag-a')->value('fingerprint');

        // Test tag filter
        $this->artisan("retry:process --tag=tag-a")
             ->expectsOutputToContain("Processing retry ID: $idA")
             ->assertExitCode(0);

        $this->assertEquals(1, DB::table('request_retries')->where('id', $idA)->value('retries_count'));
        $this->assertEquals(0, DB::table('request_retries')->where('id', $idB)->value('retries_count'));

        // Test ID filter
        $this->artisan("retry:process --id=$idB")
             ->expectsOutputToContain("Processing retry ID: $idB")
             ->assertExitCode(0);

        $this->assertEquals(1, DB::table('request_retries')->where('id', $idB)->value('retries_count'));

        // Test Fingerprint filter
        $this->artisan("retry:process --fingerprint=$fingerA")
             ->expectsOutputToContain("Processing retry ID: $idA")
             ->assertExitCode(0);

        $this->assertEquals(2, DB::table('request_retries')->where('id', $idA)->value('retries_count'));
    }

    public function test_it_avoids_duplicate_captures_with_fingerprint()
    {
        Route::post('/test-fingerprint', function () {
            return abort(500);
        })->retry(3);

        // First failure
        $this->post('/test-fingerprint', ['key' => 'value']);
        $this->assertEquals(1, DB::table('request_retries')->count());

        // Second identical failure
        $this->post('/test-fingerprint', ['key' => 'value']);
        $this->assertEquals(1, DB::table('request_retries')->count(), 'Should not have captured a duplicate pending request');

        // Different failure
        $this->post('/test-fingerprint', ['key' => 'different']);
        $this->assertEquals(2, DB::table('request_retries')->count());
    }

    public function test_it_handles_files_in_retry()
    {
        Route::post('/test-retry-file', function () {
            if (self::$shouldFail) {
                abort(500);
            }
            return response('success', 200);
        })->retry(3);

        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.pdf', 100);

        $response = $this->post('/test-retry-file', ['file' => $file]);
        $response->assertStatus(500);

        $retry = DB::table('request_retries')->where('uri', 'test-retry-file')->first();
        $filesData = json_decode($retry->files, true);
        $path = $filesData['file']['path'] ?? null;
        
        $this->assertTrue(Storage::disk('local')->exists($path));

        self::$shouldFail = false;

        $this->artisan('retry:process')
             ->assertExitCode(0);

        $this->assertDatabaseHas('request_retries', [
            'id' => $retry->id,
            'status' => 'completed',
        ]);

        $this->assertFalse(Storage::disk('local')->exists($path));
    }
}

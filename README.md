# Laravel Route Retry
![Thumbnail](assets/thumbnail.png)

Laravel Route Retry adds a simple and flexible way to capture failed requests (5xx) and retry them later. It's perfect for handling transient failures in webhooks, external API calls, or any critical route.

## Installation

You can install the package via composer:

```bash
composer require filipefernandes/laravel-route-retry
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="retry-config"
```

Then, run the migrations:

```bash
php artisan migrate
```

## Usage

### Simple Route Retry
You can enable retries on any route using the `retry()` macro:

```php
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/stripe', function () {
    // If this fails with 5xx, it will be captured
})->retry(3); // Retry up to 3 times
```

### Adding Tags
Tags help you filter and process retries for specific features:

```php
Route::post('/sync-data', [SyncController::class, 'handle'])
    ->retry(5)
    ->tags(['erp', 'priority-high']);
```

### Processing Retries
To process pending retries, add the following command to your `app/Console/Kernel.php`:

```php
$schedule->command('retry:process')->everyMinute();
```

You can also filter retries by tag or ID:

```bash
php artisan retry:process --tag=erp
php artisan retry:process --id=1 --id=2 --id=3
php artisan retry:process --fingerprint=a8...
```

## Configuration
The published configuration file `config/retry.php` allows you to customize:

- `table_name`: The table where retries are stored.
- `storage_disk`: The disk used for temporary file storage (defaults to `local`).
- `max_retries`: Global default for maximum retry attempts.
- `delay`: The initial delay before the first retry attempt.

## Events
The package dispatches events throughout the retry lifecycle:

- `LaravelRouteRetry\Events\RequestCaptured`: Dispatched when a failure is first captured.
- `LaravelRouteRetry\Events\RetrySucceeded`: Dispatched when a retry attempt results in a 2xx response.
- `LaravelRouteRetry\Events\RetryFailed`: Dispatched when a retry fails or reaches the maximum limit.

## License
The MIT License (MIT). Please see [License File](LICENSE) for more information.

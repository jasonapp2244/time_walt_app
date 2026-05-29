<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Blade directive for admin timezone display: @adminDate($date, 'd M Y H:i')
        \Illuminate\Support\Facades\Blade::directive('adminDate', function ($expression) {
            return "<?php echo ($expression) ? ($expression)->copy()->setTimezone(config('app.admin_timezone'))->format('d M Y H:i') : '—'; ?>";
        });

        \Illuminate\Support\Facades\Blade::directive('adminDateFormat', function ($expression) {
            // Usage: @adminDateFormat($date, 'format')
            list($date, $format) = array_map('trim', explode(',', $expression, 2));
            return "<?php echo ({$date}) ? ({$date})->copy()->setTimezone(config('app.admin_timezone'))->format({$format}) : '—'; ?>";
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-editable key/value settings. Reads are cached and fall back to the
 * given default (normally a config value) when the key was never saved.
 */
class AppSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    private const CACHE_KEY = 'app_settings.all';

    public static function read(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever(self::CACHE_KEY, fn () => static::pluck('value', 'key')->all());

        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
    }

    public static function write(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => is_bool($value) ? (int) $value : $value]);

        Cache::forget(self::CACHE_KEY);
    }
}

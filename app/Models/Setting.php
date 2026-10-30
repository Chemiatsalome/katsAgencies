<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("setting:{$key}", function () use ($key, $default) {
            return static::where('key', $key)->value('value') ?? $default;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting:{$key}");
    }

    /** Mirrors LandlordSetting's encrypted pair — for a platform-wide secret (e.g. the M-Pesa passkey) that shouldn't sit in plain text. */
    public static function getEncrypted(string $key, mixed $default = null): mixed
    {
        $value = static::where('key', $key)->value('value');

        return $value ? Crypt::decryptString($value) : $default;
    }

    public static function setEncrypted(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => Crypt::encryptString($value)]);
        Cache::forget("setting:{$key}");
    }

    public static function forget(string $key): void
    {
        static::where('key', $key)->delete();
        Cache::forget("setting:{$key}");
    }

    protected static function booted(): void
    {
        static::saved(fn (Setting $setting) => Cache::forget("setting:{$setting->key}"));
        static::deleted(fn (Setting $setting) => Cache::forget("setting:{$setting->key}"));
    }
}

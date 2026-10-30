<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['user_id', 'key', 'value'])]
class LandlordSetting extends Model
{
    public static function get(int $userId, string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("landlord_setting:{$userId}:{$key}", function () use ($userId, $key, $default) {
            return static::where('user_id', $userId)->where('key', $key)->value('value') ?? $default;
        });
    }

    public static function set(int $userId, string $key, mixed $value): void
    {
        static::updateOrCreate(['user_id' => $userId, 'key' => $key], ['value' => $value]);
        Cache::forget("landlord_setting:{$userId}:{$key}");
    }

    public static function getEncrypted(int $userId, string $key, mixed $default = null): mixed
    {
        $value = static::where('user_id', $userId)->where('key', $key)->value('value');

        return $value ? Crypt::decryptString($value) : $default;
    }

    public static function setEncrypted(int $userId, string $key, string $value): void
    {
        static::updateOrCreate(['user_id' => $userId, 'key' => $key], ['value' => Crypt::encryptString($value)]);
    }

    public static function forget(int $userId, string $key): void
    {
        static::where('user_id', $userId)->where('key', $key)->delete();
    }

    protected static function booted(): void
    {
        static::saved(fn (LandlordSetting $s) => Cache::forget("landlord_setting:{$s->user_id}:{$s->key}"));
        static::deleted(fn (LandlordSetting $s) => Cache::forget("landlord_setting:{$s->user_id}:{$s->key}"));
    }
}

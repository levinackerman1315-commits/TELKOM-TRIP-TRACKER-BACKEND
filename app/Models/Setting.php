<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'category'
    ];

    /**
     * Get setting value by key
     * Usage: Setting::get('price_per_km', 5000)
     */
    public static function get(string $key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        
        if (!$setting) {
            return $default;
        }

        // Cast value based on type
        return match($setting->type) {
            'integer' => (int) $setting->value,
            'boolean' => (bool) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    /**
     * Set setting value by key
     * Usage: Setting::set('price_per_km', 6000)
     */
    public static function set(string $key, $value): bool
    {
        $setting = self::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value]
        );

        return $setting->wasRecentlyCreated || $setting->wasChanged();
    }
}
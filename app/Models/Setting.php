<?php

namespace App\Models;

use App\Services\ScheduleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        $row = static::query()->where('key', $key)->first();

        return $row?->value ?? $default;
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::query()->upsert(
            [['key' => $key, 'value' => $value]],
            ['key'],
            ['value']
        );

        if (in_array($key, ['shift_start', 'shift_end', 'default_work_days', 'default_break_minutes'], true)
            && Schema::hasTable('default_schedule_versions')) {
            app(ScheduleService::class)->recordDefaultVersion(
                auth()->id(),
                'Default schedule changed through application configuration'
            );
        }
    }
}

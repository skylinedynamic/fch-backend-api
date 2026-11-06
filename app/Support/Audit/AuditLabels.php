<?php

namespace App\Support\Audit;

use Illuminate\Support\Str;

/** The words the audit trail shows for its events, fields and files. */
final class AuditLabels
{
    public static function event(string $event): string
    {
        $label = __("audit.events.{$event}");

        return $label !== "audit.events.{$event}" ? $label : Str::headline($event);
    }

    public static function field(string $field): string
    {
        $label = __("audit.fields.{$field}");

        return $label !== "audit.fields.{$field}" ? $label : Str::headline($field);
    }

    public static function file(string $fileType): string
    {
        $label = __("audit.files.{$fileType}");

        return $label !== "audit.files.{$fileType}" ? $label : Str::headline($fileType);
    }

    /** Each changed field as audit.change ("Label: old → new"), joined. */
    public static function changes(?array $changes): string
    {
        return collect($changes ?? [])
            ->map(fn ($c) => __('audit.change', [
                'field' => self::field($c['field']),
                'old' => $c['old'] ?? __('audit.empty_value'),
                'new' => $c['new'] ?? __('audit.empty_value'),
            ]))
            ->implode(__('audit.separator'));
    }

    /** The event's context as audit.context_item ("key=value"), joined. */
    public static function context(?array $context): string
    {
        return collect($context ?? [])
            ->map(fn ($v, $k) => __('audit.context_item', [
                'key' => $k,
                'value' => is_bool($v) ? __($v ? 'audit.yes' : 'audit.no') : (is_array($v) ? json_encode($v) : $v),
            ]))
            ->implode(__('audit.separator'));
    }
}

<?php

namespace App\Services\Global\Datatable;

use Illuminate\Database\Eloquent\Builder;

class QueryFilter
{
    /**
     * Apply PrimeReact-style or simple filters to an Eloquent query.
     * - Ignores 'operator' and treats multiple constraints as ANDed 'where' clauses.
     * - Accepts array or JSON string for $rawFilters.
     *
     * Options:
     * - aliases: map of logical fields to actual column names (e.g., ['phone_number' => 'phone_number_1'])
     * - full_name_field: logical field name for concatenated name (default 'full_name')
     * - full_name_columns: columns used for concatenation (default ['first_name','last_name'])
     */
    public static function apply(Builder $query, string $sortField = 'id', string $sortOrder = 'desc', $rawFilters = [], array $options = []): Builder
    {
        // Decode JSON filters if needed
        if (is_string($rawFilters)) {
            $decoded = json_decode($rawFilters, true);
            $rawFilters = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($rawFilters)) {
            $rawFilters = [];
        }

        $aliases = $options['aliases'] ?? [];

        // Handle concatenated fields (like full_name)
        $concatFields = $options['concat_fields'] ?? [];
        if (isset($options['full_name_field'])) {
            $concatFields[$options['full_name_field']] = $options['full_name_columns'] ?? ['first_name', 'last_name'];
        }

        // Apply filters
        foreach ($rawFilters as $field => $def) {
            if (is_array($def) && isset($def['constraints'])) {
                // PrimeReact style constraints
                $constraints = $def['constraints'];
                $query->where(function ($q) use ($constraints, $field, $aliases, $concatFields) {
                    foreach ($constraints as $c) {
                        $value = $c['value'] ?? null;
                        $mode = $c['matchMode'] ?? 'contains';
                        if ($value === null || $value === '') continue;

                        if (array_key_exists($field, $concatFields)) {
                            $columns = $concatFields[$field];
                            self::applyConcatConstraint($q, $mode, (string)$value, $columns);
                            continue;
                        }

                        $column = $aliases[$field] ?? $field;
                        self::applyConstraint($q, $column, $mode, (string)$value);
                    }
                });
            } elseif (is_array($def) && isset($def['value']) && trim((string)$def['value']) !== '') {
                // Simple filter
                $value = trim((string)$def['value']);
                if (array_key_exists($field, $concatFields)) {
                    $columns = $concatFields[$field];
                    self::applyConcatConstraint($query, 'contains', $value, $columns);
                    continue;
                }
                $column = $aliases[$field] ?? $field;
                $query->where($column, 'like', "%{$value}%");
            }
        }

        // Apply sorting
        $sortOrder = strtolower($sortOrder) === 'desc' ? 'desc' : 'asc';
        if (array_key_exists($sortField, $concatFields)) {
            // Sorting by concatenated field
            $columns = $concatFields[$sortField];
            $expr = self::buildConcatExpr($columns);
            $query->orderByRaw("{$expr} {$sortOrder}");
        } else {
            $column = $aliases[$sortField] ?? $sortField;
            $query->orderBy($column, $sortOrder);
        }

        return $query;
    }

    /**
     * Apply a single constraint for a given column and mode.
     */
    protected static function applyConstraint(Builder $q, string $column, ?string $mode, string $value): void
    {
        $likeContains = "%{$value}%";
        $likeStarts = "{$value}%";
        $likeEnds = "%{$value}";

        switch ($mode) {
            case 'contains':
                $q->where($column, 'like', $likeContains);
                break;
            case 'startsWith':
                $q->where($column, 'like', $likeStarts);
                break;
            case 'endsWith':
                $q->where($column, 'like', $likeEnds);
                break;
            case 'equals':
                $q->where($column, '=', $value);
                break;
            case 'notEquals':
                $q->where($column, '!=', $value);
                break;
            case 'in':
                $vals = is_array($value) ? $value : explode(',', $value);
                $q->whereIn($column, $vals);
                break;
            case 'lt':
                $q->where($column, '<', $value);
                break;
            case 'lte':
                $q->where($column, '<=', $value);
                break;
            case 'gt':
                $q->where($column, '>', $value);
                break;
            case 'gte':
                $q->where($column, '>=', $value);
                break;
            default:
                // Fallback to contains
                $q->where($column, 'like', $likeContains);
        }
    }

    /**
     * Apply constraint for a concatenated full_name.
     */
    protected static function applyConcatConstraint(Builder $q, ?string $mode, string $value, array $columns, string $glue = ' '): void
    {
        $expr = self::buildConcatExpr($columns, $glue);

        $likeContains = "%{$value}%";
        $likeStarts = "{$value}%";
        $likeEnds = "%{$value}";

        $callback = function ($builder) use ($mode, $expr, $likeContains, $likeStarts, $likeEnds, $value) {
            switch ($mode) {
                case 'contains':
                    $builder->whereRaw("{$expr} LIKE ?", [$likeContains]);
                    break;
                case 'startsWith':
                    $builder->whereRaw("{$expr} LIKE ?", [$likeStarts]);
                    break;
                case 'endsWith':
                    $builder->whereRaw("{$expr} LIKE ?", [$likeEnds]);
                    break;
                case 'equals':
                    $builder->whereRaw("{$expr} = ?", [$value]);
                    break;
                case 'notEquals':
                    $builder->whereRaw("{$expr} != ?", [$value]);
                    break;
                default:
                    $builder->whereRaw("{$expr} LIKE ?", [$likeContains]);
            }
        };

        $q->where($callback);
    }

    /**
     * Build CONCAT_WS expression for MySQL.
     */
    protected static function buildConcatExpr(array $columns, string $glue = ' '): string
    {
        // Use CONCAT_WS for flexible concatenation with a glue across N columns
        $cols = implode(', ', array_map(function ($c) { return $c; }, $columns));
        $glue = addslashes($glue);
        return "CONCAT_WS('{$glue}', {$cols})";
    }
}

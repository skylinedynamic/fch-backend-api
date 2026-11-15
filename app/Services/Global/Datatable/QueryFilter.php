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
    public static function apply(Builder $query, $sortField, $sortOrder, $rawFilters, array $options = []): Builder
    {
        // Apply sorting - on a column the caller may use, else by the key.
        if (!($options['skip_sorting'] ?? false)) {
            $query->orderBy(
                self::isUsableColumn($query, (string) $sortField) ? $sortField : $query->getModel()->getQualifiedKeyName(),
                $sortOrder
            );
        }
        
        if (is_string($rawFilters)) {
            $decoded = json_decode($rawFilters, true);
            $rawFilters = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($rawFilters) || empty($rawFilters)) {
            return $query;
        }

        $aliases = $options['aliases'] ?? [];
        // Support multiple concatenated fields. Backward compatible with full_name_field/full_name_columns.
        $concatFields = $options['concat_fields'] ?? [];
        if (isset($options['full_name_field'])) {
            $concatFields[$options['full_name_field']] = $options['full_name_columns'] ?? ['first_name', 'last_name'];
        }

        // Check if using constraints format
        $hasConstraints = false;
        foreach ($rawFilters as $def) {
            if (is_array($def) && array_key_exists('constraints', $def)) {
                $hasConstraints = true;
                break;
            }
        }

        if ($hasConstraints) {
            foreach ($rawFilters as $field => $filterData) {
                if (!isset($filterData['constraints']) || !is_array($filterData['constraints'])) {
                    continue;
                }

                $constraints = $filterData['constraints'];

                $query->where(function ($q) use ($constraints, $field, $aliases, $concatFields) {
                    foreach ($constraints as $c) {
                        $value = $c['value'] ?? null;
                        $mode = $c['matchMode'] ?? null;
                        if ($value === null || $value === '') continue;

                        // Concatenated logical fields
                        if (array_key_exists($field, $concatFields)) {
                            $def = $concatFields[$field];
                            $columns = is_array($def) && isset($def['columns']) ? (array) $def['columns'] : (array) $def;
                            $glue = is_array($def) && isset($def['glue']) ? (string) $def['glue'] : ' ';
                            self::applyConcatConstraint($q, $mode, (string) $value, $columns, $glue);
                            continue;
                        }

                        $column = $aliases[$field] ?? $field;
                        if (!self::isUsableColumn($q, (string) $column)) {
                            continue;
                        }
                        self::applyConstraint($q, $column, $mode, (string) $value);
                    }
                });
            }
        } else {
            // Simple filters: { field: { value } }
            foreach ($rawFilters as $field => $def) {
                if (!is_array($def) || empty($def['value'])) continue;
                $value = trim((string) $def['value']);
                if ($value === '') continue;

                // Concatenated logical fields
                if (array_key_exists($field, $concatFields)) {
                    $defn = $concatFields[$field];
                    $columns = is_array($defn) && isset($defn['columns']) ? (array) $defn['columns'] : (array) $defn;
                    $glue = is_array($defn) && isset($defn['glue']) ? (string) $defn['glue'] : ' ';
                    self::applyConcatConstraint($query, 'contains', $value, $columns, $glue);
                } else {
                    $column = $aliases[$field] ?? $field;
                    if (!self::isUsableColumn($query, (string) $column)) {
                        continue;
                    }
                    $query->where($column, 'like', "%{$value}%");
                }
            }
        }

        

        return $query;
    }

    /**
     * The first value typed in one column's filter, for a column that is not a
     * table column (a related model's name, say) and so needs its own query.
     * Accepts the same PrimeReact-style or simple filters as apply().
     */
    public static function value($rawFilters, string $field): ?string
    {
        if (is_string($rawFilters)) {
            $rawFilters = json_decode($rawFilters, true);
        }

        $def = is_array($rawFilters) ? ($rawFilters[$field] ?? null) : null;
        if (!is_array($def)) {
            return null;
        }

        $constraints = isset($def['constraints']) && is_array($def['constraints']) ? $def['constraints'] : [$def];
        foreach ($constraints as $c) {
            $value = is_array($c) ? trim((string) ($c['value'] ?? '')) : '';
            if ($value !== '') {
                return $value;
            }
        }

        return null;
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

        $q->where(function ($builder) use ($callback) { $callback($builder); });
    }

    /**
     * Column names that are never filtered or sorted on, whatever the model
     * hides: a filter answers yes/no per row, and "startsWith" repeated one
     * character at a time reads the value out.
     */
    private const SECRET_COLUMN_PATTERN = '/(password|secret|token|ssn|recovery_code|api_key|card_no|account_number|routing_number)/i';

    /**
     * Whether a request-supplied field may be used as a column: a plain
     * (optionally table-qualified) column name, not one the model hides, and
     * not a secret.
     */
    protected static function isUsableColumn(Builder $query, string $column): bool
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
            return false;
        }

        $name = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;

        if (preg_match(self::SECRET_COLUMN_PATTERN, $name)) {
            return false;
        }

        return !in_array($name, $query->getModel()->getHidden(), true);
    }

    protected static function buildConcatExpr(array $columns, string $glue = ' '): string
    {
        // Use CONCAT_WS for flexible concatenation with a glue across N columns
        $cols = implode(', ', array_map(function ($c) { return $c; }, $columns));
        $glue = addslashes($glue);
        return "CONCAT_WS('{$glue}', {$cols})";
    }
}
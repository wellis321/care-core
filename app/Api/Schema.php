<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Clock;

/**
 * Checks a JSON request body against a small field specification, so every endpoint refuses the same kinds of mistake in the
 * same way: unknown fields, wrong types, missing required fields, values outside a list.
 *
 * A field spec: ['type' => string|date|int|number|bool|enum|uuid|array, 'required' => bool, 'enum' => [...], 'max' => int,
 *                'min' => number, 'nullable' => bool, 'desc' => string]
 */
final class Schema
{
    /**
     * @param array<string,array<string,mixed>> $spec
     * @param array<string,mixed> $input
     * @return array<string,mixed> the accepted fields (only those present)
     */
    public static function validate(array $spec, array $input, bool $partial = false): array
    {
        $errors = [];
        $out = [];
        foreach (array_diff_key($input, $spec) as $name => $_) {
            $errors[] = ['field' => (string) $name, 'message' => 'Unknown field.'];
        }
        foreach ($spec as $name => $f) {
            if (!array_key_exists($name, $input)) {
                if (!empty($f['required']) && !$partial) {
                    $errors[] = ['field' => $name, 'message' => 'This field is required.'];
                }
                continue;
            }
            $v = $input[$name];
            if ($v === null) {
                if (!empty($f['required']) && empty($f['nullable'])) {
                    $errors[] = ['field' => $name, 'message' => 'This field cannot be null.'];
                } else {
                    $out[$name] = null;
                }
                continue;
            }
            if ($msg = self::check($f, $v)) {
                $errors[] = ['field' => $name, 'message' => $msg];
                continue;
            }
            $out[$name] = is_string($v) ? trim($v) : $v;
        }
        if ($errors) {
            throw ApiException::invalid('The request body has ' . count($errors) . ' problem' . (count($errors) === 1 ? '' : 's') . '.', $errors);
        }
        return $out;
    }

    /** @param array<string,mixed> $f */
    private static function check(array $f, mixed $v): ?string
    {
        $type = $f['type'] ?? 'string';
        switch ($type) {
            case 'string':
                if (!is_string($v)) {
                    return 'Must be a string.';
                }
                if (isset($f['max']) && mb_strlen($v) > $f['max']) {
                    return 'Must be at most ' . $f['max'] . ' characters.';
                }
                return null;
            case 'date':
                return is_string($v) && Clock::valid($v) ? null : 'Must be a real date in the form YYYY-MM-DD.';
            case 'int':
                if (!is_int($v)) {
                    return 'Must be a whole number.';
                }
                return (isset($f['min']) && $v < $f['min']) || (isset($f['max']) && $v > $f['max']) ? 'Out of range.' : null;
            case 'number':
                if (!is_int($v) && !is_float($v)) {
                    return 'Must be a number.';
                }
                return (isset($f['min']) && $v < $f['min']) || (isset($f['max']) && $v > $f['max']) ? 'Out of range.' : null;
            case 'bool':
                return is_bool($v) ? null : 'Must be true or false.';
            case 'enum':
                return is_string($v) && in_array($v, $f['enum'], true) ? null : 'Must be one of: ' . implode(', ', $f['enum']) . '.';
            case 'uuid':
                return is_string($v) && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $v) ? null : 'Must be an id (UUID).';
            case 'array':
                return is_array($v) ? null : 'Must be a list.';
        }
        return null;
    }
}

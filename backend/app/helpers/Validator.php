<?php

/**
 * VCMS — Village Cooperative Management System
 * app/helpers/Validator.php — Input validation helper
 *
 * Usage:
 *   $result = Validator::validate($data, [
 *       'full_name' => 'required|type:string|maxLength:100',
 *       'phone'     => 'required|digits:7,15',
 *       'amount'    => 'required|type:decimal|positive|decimal_places:2',
 *       'status'    => 'required|type:enum:Active,Inactive',
 *   ]);
 *   if (!$result['valid']) { ... $result['errors'] ... }
 */

declare(strict_types=1);

namespace App\Helpers;

class Validator
{
    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validate an associative array of data against a rule map.
     *
     * @param array<string, mixed>  $data   Associative input data
     * @param array<string, string> $rules  Field → pipe-separated rule string
     *
     * @return array{valid: bool, errors: array<string, string>}
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            $error = self::validateField($value, $ruleString, $field);
            if ($error !== null) {
                $errors[$field] = $error;
            }
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate a single value against a pipe-separated rule string.
     *
     * @param mixed  $value     The value to validate (from $data[$field])
     * @param string $ruleString Pipe-separated rules, e.g. 'required|type:int|min:1'
     * @param string $fieldName  Used in error messages (optional, defaults to 'field')
     *
     * @return string|null  Error message, or null if valid
     */
    public static function validateField(
        mixed $value,
        string $ruleString,
        string $fieldName = 'field'
    ): ?string {
        $ruleTokens = explode('|', $ruleString);

        // Determine if "required" is listed so we can skip other rules on absent values
        $isRequired = in_array('required', $ruleTokens, true);

        // For absent / null / empty-string values: only "required" is checked
        $isEmpty = ($value === null || $value === '');
        if ($isEmpty) {
            if ($isRequired) {
                return self::humanLabel($fieldName) . ' is required.';
            }
            // Field is optional and absent — skip all remaining rules
            return null;
        }

        // Run each rule
        foreach ($ruleTokens as $token) {
            $token = trim($token);
            if ($token === '' || $token === 'required') {
                continue;
            }

            $error = self::applyRule($value, $token, $fieldName);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Apply a single rule token to a value and return an error string or null.
     */
    private static function applyRule(mixed $value, string $token, string $fieldName): ?string
    {
        $label = self::humanLabel($fieldName);

        // ── type:* ────────────────────────────────────────────────────────────
        if (str_starts_with($token, 'type:')) {
            $typeSpec = substr($token, 5); // e.g. "int", "decimal", "string", "enum:a,b"

            if ($typeSpec === 'int') {
                if (!self::isIntegerValue($value)) {
                    return "{$label} must be an integer.";
                }
                return null;
            }

            if ($typeSpec === 'decimal') {
                if (!is_numeric($value)) {
                    return "{$label} must be a numeric value.";
                }
                return null;
            }

            if ($typeSpec === 'string') {
                if (!is_string($value)) {
                    return "{$label} must be a string.";
                }
                return null;
            }

            if (str_starts_with($typeSpec, 'enum:')) {
                $allowed = explode(',', substr($typeSpec, 5));
                $allowed = array_map('trim', $allowed);
                if (!in_array((string) $value, $allowed, true)) {
                    return "{$label} must be one of: " . implode(', ', $allowed) . '.';
                }
                return null;
            }

            return "{$label} has an unknown type rule: {$typeSpec}.";
        }

        // ── min:N ─────────────────────────────────────────────────────────────
        if (str_starts_with($token, 'min:')) {
            $n = (float) substr($token, 4);
            if (!is_numeric($value)) {
                return "{$label} must be a number for min validation.";
            }
            if ((float) $value < $n) {
                return "{$label} must be at least {$n}.";
            }
            return null;
        }

        // ── max:N ─────────────────────────────────────────────────────────────
        if (str_starts_with($token, 'max:')) {
            $n = (float) substr($token, 4);
            if (!is_numeric($value)) {
                return "{$label} must be a number for max validation.";
            }
            if ((float) $value > $n) {
                return "{$label} must be at most {$n}.";
            }
            return null;
        }

        // ── minLength:N ───────────────────────────────────────────────────────
        if (str_starts_with($token, 'minLength:')) {
            $n = (int) substr($token, 10);
            $len = mb_strlen((string) $value, 'UTF-8');
            if ($len < $n) {
                return "{$label} must be at least {$n} character(s) long.";
            }
            return null;
        }

        // ── maxLength:N ───────────────────────────────────────────────────────
        if (str_starts_with($token, 'maxLength:')) {
            $n = (int) substr($token, 10);
            $len = mb_strlen((string) $value, 'UTF-8');
            if ($len > $n) {
                return "{$label} must not exceed {$n} character(s).";
            }
            return null;
        }

        // ── regex:/pattern/ ───────────────────────────────────────────────────
        if (str_starts_with($token, 'regex:')) {
            $pattern = substr($token, 6);
            $result  = @preg_match($pattern, (string) $value);
            if ($result === false) {
                return "{$label} has an invalid regex pattern.";
            }
            if ($result === 0) {
                return "{$label} has an invalid format.";
            }
            return null;
        }

        // ── digits:min,max ────────────────────────────────────────────────────
        // String of all-digit characters with length between min and max.
        if (str_starts_with($token, 'digits:')) {
            [$minLen, $maxLen] = array_map('intval', explode(',', substr($token, 7), 2));
            $str = (string) $value;
            if (!ctype_digit($str)) {
                return "{$label} must contain digits only.";
            }
            $len = strlen($str);
            if ($len < $minLen || $len > $maxLen) {
                return "{$label} must be between {$minLen} and {$maxLen} digits long.";
            }
            return null;
        }

        // ── decimal_places:N ──────────────────────────────────────────────────
        if (str_starts_with($token, 'decimal_places:')) {
            $n = (int) substr($token, 15);
            if (!is_numeric($value)) {
                return "{$label} must be a numeric value.";
            }
            $str = rtrim(number_format((float) $value, $n + 5, '.', ''), '0');
            // Count digits after decimal point
            $parts = explode('.', (string) $value);
            $decimals = isset($parts[1]) ? strlen(rtrim($parts[1], '0')) : 0;
            if ($decimals > $n) {
                return "{$label} must have at most {$n} decimal place(s).";
            }
            return null;
        }

        // ── positive ──────────────────────────────────────────────────────────
        if ($token === 'positive') {
            if (!is_numeric($value)) {
                return "{$label} must be a numeric value.";
            }
            if ((float) $value <= 0) {
                return "{$label} must be greater than zero.";
            }
            return null;
        }

        // ── date (Y-m-d) ──────────────────────────────────────────────────────
        if ($token === 'date') {
            $d = \DateTime::createFromFormat('Y-m-d', (string) $value);
            if ($d === false || $d->format('Y-m-d') !== (string) $value) {
                return "{$label} must be a valid date in Y-m-d format.";
            }
            return null;
        }

        // Unknown rule — silently skip to avoid breaking valid code
        return null;
    }

    /**
     * Check that a value is an integer or a string representation of an integer
     * (no decimal point, no exponent notation).
     */
    private static function isIntegerValue(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_string($value) || is_float($value)) {
            // Accept only numeric strings without a decimal point or exponent
            $str = (string) $value;
            return (bool) preg_match('/^-?\d+$/', $str);
        }
        return false;
    }

    /**
     * Convert a snake_case or camelCase field name into a human-readable label.
     * E.g. "full_name" → "Full name", "phoneNumber" → "Phone number"
     */
    private static function humanLabel(string $fieldName): string
    {
        // Replace underscores and hyphens with spaces
        $str = str_replace(['_', '-'], ' ', $fieldName);

        // Insert space before uppercase letters in camelCase
        $str = preg_replace('/([a-z])([A-Z])/', '$1 $2', $str) ?? $str;

        return ucfirst(strtolower($str));
    }
}

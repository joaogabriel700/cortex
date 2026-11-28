<?php

namespace App\Support\Http;

use Closure;

/**
 * Strict validation of a JSON object. The framework rules accept "100" as an integer and
 * "1" as a boolean, and do not refuse unknown fields.
 */
class Fields
{
    /**
     * Every field of $rules is required (the contract replaces whole records) and no other
     * field is accepted. A rule returns null when the value is valid, or an error code.
     *
     * @param  array<array-key, mixed>  $input
     * @param  array<string, Closure(mixed): ?string>  $rules
     * @return array<string, mixed>
     */
    public static function check(array $input, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            $code = array_key_exists($field, $input) ? $rule($input[$field]) : 'required';

            if ($code !== null) {
                $errors[] = [$field, $code];
            }
        }

        foreach (array_keys(array_diff_key($input, $rules)) as $unknown) {
            $errors[] = [(string) $unknown, 'prohibited'];
        }

        if ($errors !== []) {
            throw self::failure($errors);
        }

        return $input;
    }

    /** @param  list<array{0: string, 1: string}>  $errors */
    public static function failure(array $errors): ProblemException
    {
        return new ProblemException('validation_failed', 422, ['errors' => array_map(fn (array $error) => [
            'field' => $error[0], 'code' => $error[1], 'message' => __("fields.{$error[1]}"),
        ], $errors)]);
    }
}

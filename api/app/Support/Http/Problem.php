<?php

namespace App\Support\Http;

use App\Http\Middleware\SetRequestLocale;
use App\Support\Tenancy\CommitFailedException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** RFC 9457 errors. Never carries exception messages, SQL or submitted values. */
class Problem
{
    private const BY_STATUS = [
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        419 => 'csrf_token_mismatch',
        429 => 'too_many_requests',
        503 => 'temporarily_unavailable',
    ];

    /** Serialization failure, deadlock, lock or statement timeout. */
    private const TRANSIENT_SQLSTATES = ['40001', '40P01', '55P03', '57014'];

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*', 'sanctum/*') || $e instanceof HttpResponseException) {
            return null;
        }

        // Errors raised before the locale middleware (unknown route) still follow the header.
        SetRequestLocale::apply(
            auth()->hasUser() ? auth()->user()->locale : SetRequestLocale::fromHeader($request),
        );

        return match (true) {
            $e instanceof ValidationException => self::make('validation_failed', 422, extra: ['errors' => self::errors($e)]),
            $e instanceof ProblemException => self::make($e->problem, $e->status, extra: $e->extra),
            $e instanceof AuthenticationException => self::make('unauthenticated', 401),
            $e instanceof HttpExceptionInterface => self::make(
                self::BY_STATUS[$e->getStatusCode()] ?? 'internal_error', $e->getStatusCode(), $e->getHeaders(),
            ),
            ($e instanceof QueryException || $e instanceof CommitFailedException)
                && in_array((string) $e->getCode(), self::TRANSIENT_SQLSTATES, true) => self::make(
                    'temporarily_unavailable', 503, ['Retry-After' => 2],
                ),
            default => self::make('internal_error', 500),
        };
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $extra
     */
    public static function make(string $code, int $status, array $headers = [], array $extra = []): JsonResponse
    {
        return new JsonResponse([
            'type' => "urn:cortex:problem:{$code}",
            'title' => __("problems.{$code}"),
            'status' => $status,
            'code' => $code,
            'request_id' => (string) Str::uuid7(),
        ] + $extra, $status, $headers + [
            'Content-Type' => 'application/problem+json',
            'Content-Language' => str_replace('_', '-', App::getLocale()),
        ]);
    }

    /** @return list<array{field: string, code: string, message: string}> */
    private static function errors(ValidationException $e): array
    {
        $errors = [];

        foreach ($e->validator->errors()->messages() as $field => $messages) {
            $rule = array_key_first($e->validator->failed()[$field] ?? []) ?? 'invalid';
            $errors[] = ['field' => (string) $field, 'code' => Str::snake((string) $rule), 'message' => $messages[0]];
        }

        return $errors;
    }
}

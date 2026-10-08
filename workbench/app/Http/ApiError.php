<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The one shape of a JSON error (exceptions.md): `{message, code}`, plus `errors` on a 422.
 *
 * It is Laravel's own shape with a stable `code` added, so a client written against Laravel
 * keeps working and a client that branches on the reason reads `code`, never `message`.
 * `message` is always translated text written for a person. An exception's own message is
 * written for the log and never reaches the client, except a policy's deny message and the
 * validation summary, which are written for the user.
 */
final class ApiError
{
    public const int REFUSED_STATUS = 409;

    /**
     * @var array<int, array{0: string, 1: string}> status => [code, translation key]
     */
    private const array STATUSES = [
        401 => ['unauthenticated', 'common.unauthenticated'],
        403 => ['forbidden', 'common.forbidden'],
        404 => ['not_found', 'common.not_found'],
        405 => ['method_not_allowed', 'common.method_not_allowed'],
        419 => ['page_expired', 'common.page_expired'],
        429 => ['too_many_requests', 'common.too_many_requests'],
        503 => ['service_unavailable', 'common.service_unavailable'],
    ];

    /**
     * Fields Laravel adds to a JSON error while debug is on, kept so a developer still sees
     * the trace.
     */
    private const array DEBUG_FIELDS = ['exception', 'file', 'line', 'trace'];

    /**
     * A refusal the controller caught, answered as 409 with the code the client branches on.
     */
    public static function refused(string $code, string $message): JsonResponse
    {
        return self::make(self::REFUSED_STATUS, $code, $message);
    }

    /**
     * The JSON body for a response Laravel built from an exception nobody caught. The status
     * and the headers (`Retry-After`, `WWW-Authenticate`) stay as Laravel set them.
     */
    public static function fromResponse(Response $response, Throwable $exception): JsonResponse
    {
        $status = $response->getStatusCode();

        if ($exception instanceof ValidationException) {
            $json = self::make($status, 'validation_failed', $exception->getMessage(), ['errors' => $exception->errors()]);
        } elseif ($status === 403 && $exception->getMessage() !== '') {
            $json = self::make($status, 'forbidden', $exception->getMessage());
        } elseif (isset(self::STATUSES[$status])) {
            [$code, $key] = self::STATUSES[$status];
            $json = self::make($status, $code, __($key));
        } elseif ($status >= 500) {
            $json = self::make($status, 'server_error', __('common.server_error'));
        } else {
            $json = self::make($status, 'http_'.$status, Response::$statusTexts[$status] ?? 'Error');
        }

        $json->headers->add(Arr::except($response->headers->all(), ['content-type', 'content-length']));

        if (config('app.debug')) {
            $original = json_decode((string) $response->getContent(), true);

            $json->setData([...(array) $json->getData(true), ...Arr::only(is_array($original) ? $original : [], self::DEBUG_FIELDS)]);
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function make(int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code, ...$extra], $status);
    }
}

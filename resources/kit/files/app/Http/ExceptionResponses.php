<?php

namespace App\Http;

use App\Domain\Shared\Exceptions\EntityNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one place an exception nobody caught becomes a response (exceptions.md).
 *
 * A refusal the user can act on is caught by its controller, which picks the message. What
 * reaches this class is either not found, forbidden, an expired page, or a failure — and each
 * of those has one answer for every page, so no controller writes it twice.
 *
 * `register()` hooks Laravel's handler from bootstrap/app.php. `respond()` sees the finished
 * response, so it is registered through `Inertia::handleExceptionsUsing()` in a provider:
 * Laravel keeps a single `respondUsing` slot, and Inertia's hook needs the built handler.
 */
final class ExceptionResponses
{
    public const string ERROR_PAGE = 'error';

    /**
     * Statuses that render the error page when debug is off. Every other status keeps
     * Laravel's own response.
     */
    public const array ERROR_PAGE_STATUSES = [403, 404, 500, 503];

    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(self::wantsJson(...));

        /**
         * A handler that cannot find what the request named answers like a route model
         * binding does. The HttpException is not reported, so a stale link stays out of the log.
         */
        $exceptions->map(fn (EntityNotFoundException $e) => new NotFoundHttpException(previous: $e));

        /**
         * A refused Inertia visit stays on its page with a toast. `abort(403)` carries no
         * message, and an error toast stays until it is closed, so it always says why.
         */
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request): ?RedirectResponse {
            if (! self::isInertia($request)) {
                return null;
            }

            return self::toastBack($e->getMessage() !== '' ? $e->getMessage() : __('common.forbidden'));
        });

        /**
         * A write to a row someone else removed first is a race, not a broken link: the user
         * stays on the page they acted from and learns the row is gone.
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request): ?RedirectResponse {
            if (! self::isInertia($request) || $request->isMethod('GET')) {
                return null;
            }

            return self::toastBack(__('common.not_found'));
        });
    }

    /**
     * Returning null keeps the response Laravel built. An `HttpResponseException` already
     * carries the response its thrower chose, so it is kept as is.
     */
    public static function respond(ExceptionResponse $response): ?Response
    {
        $request = $response->request;
        $status = $response->statusCode();

        if ($status < 400 || $response->exception instanceof HttpResponseException) {
            return null;
        }

        if (self::wantsJson($request)) {
            return ApiError::fromResponse($response->response, $response->exception);
        }

        if ($status === 419 && self::isInertia($request)) {
            return self::toastBack(__('common.page_expired'));
        }

        if (! config('app.debug') && in_array($status, self::ERROR_PAGE_STATUSES, true)) {
            return $response->render(self::ERROR_PAGE, ['status' => $status])->withSharedData()->toResponse($request);
        }

        return null;
    }

    /**
     * An Inertia visit never answers in JSON, even when it asks for it: Inertia reads its own
     * page responses and would show a JSON body in its error modal.
     */
    public static function wantsJson(Request $request): bool
    {
        return ! self::isInertia($request) && ($request->is('api/*') || $request->expectsJson());
    }

    private static function isInertia(Request $request): bool
    {
        return $request->hasHeader(Header::INERTIA);
    }

    private static function toastBack(string $message): RedirectResponse
    {
        return Inertia::flash(FlashToast::KEY, FlashToast::error($message))->back();
    }
}

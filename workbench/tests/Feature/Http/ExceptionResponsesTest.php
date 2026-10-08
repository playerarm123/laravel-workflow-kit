<?php

use App\Domain\Shared\Exceptions\EntityNotFoundException;
use App\Http\ApiError;
use App\Http\ExceptionResponses;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The kit test of exceptions.md: every branch of `ExceptionResponses` and `ApiError`, proven
 * on throwaway routes so it runs in any project. The same throw answers a web visit, an
 * Inertia visit and a JSON client each in its own way.
 */
function exceptionResponsesThrowers(): array
{
    return [
        'not-found' => fn () => throw EntityNotFoundException::byId('missing-id'),
        'forbidden' => fn () => throw new AuthorizationException('Only the owner may do this.'),
        'forbidden-silent' => fn () => throw new AccessDeniedHttpException,
        'unauthenticated' => fn () => throw new AuthenticationException,
        'expired' => fn () => throw new TokenMismatchException,
        'throttled' => fn () => throw new ThrottleRequestsException(headers: ['Retry-After' => '30']),
        'teapot' => fn () => throw new HttpException(418),
        'failure' => fn () => throw new RuntimeException('secret detail for the log'),
        'maintenance' => fn () => throw new ServiceUnavailableHttpException,
        'kept' => fn () => throw new HttpResponseException(new JsonResponse(['kept' => true], 400)),
        'refused' => fn () => ApiError::refused('insufficient_balance', 'Your balance is too low.'),
        'validated' => fn (Request $request) => $request->validate(['name' => ['required']]),
    ];
}

beforeEach(function () {
    config(['app.debug' => false]);

    foreach (exceptionResponsesThrowers() as $name => $thrower) {
        Route::middleware('web')->match(['GET', 'POST'], "_exceptions/{$name}", $thrower);
        Route::middleware('api')->match(['GET', 'POST'], "api/_exceptions/{$name}", $thrower);
    }

    Route::middleware('api')->get('api/_exceptions/users/{user}', fn (User $user) => $user->id);
});

function exceptionResponsesInertia(): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
    ];
}

describe('a web visit', function () {
    it('renders the error page for each status it covers while debug is off', function (string $route, int $status) {
        $this->get("/_exceptions/{$route}")
            ->assertStatus($status)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component(ExceptionResponses::ERROR_PAGE)
                ->where('status', $status)
                ->has('translations'));
    })->with([
        'a missing entity' => ['not-found', 404],
        'a refusal' => ['forbidden', 403],
        'a failure' => ['failure', 500],
        'maintenance' => ['maintenance', 503],
    ]);

    it('keeps the debug page while debug is on', function () {
        config(['app.debug' => true]);

        $response = $this->get('/_exceptions/failure')->assertStatus(500);

        expect($response->headers->get('X-Inertia'))->toBeNull()
            ->and($response->getContent())->not->toContain('&quot;component&quot;:&quot;error&quot;');
    });

    /**
     * Watched on the real handler: `Exceptions::fake()` records every throw before Laravel maps
     * it, so it would count the missing entity the real handler drops. The callback returns
     * false, so nothing reaches the log.
     */
    it('reports a failure and never a missing entity', function () {
        $handler = app(ExceptionHandler::class);
        $reported = [];
        $handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e::class;

            return false;
        });

        $handler->report(new RuntimeException('broken'));
        $handler->report(EntityNotFoundException::byId('missing-id'));

        expect($reported)->toBe([RuntimeException::class]);
    });
});

describe('an inertia visit', function () {
    it('stays on the page with a toast when it is refused', function (string $route, string $message) {
        $this->from('/somewhere')
            ->withHeaders(exceptionResponsesInertia())
            ->get("/_exceptions/{$route}")
            ->assertRedirect('/somewhere')
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.title', __('common.toast_error_title'))
            ->assertInertiaFlash('toast.message', $message);
    })->with([
        'the policy message' => ['forbidden', 'Only the owner may do this.'],
        'no message' => ['forbidden-silent', 'You are not allowed to do this.'],
        'an expired page' => ['expired', 'The page expired. Please try again.'],
    ]);

    it('stays on the page with a toast when a write finds its row gone', function () {
        $this->from('/somewhere')
            ->withHeaders(exceptionResponsesInertia())
            ->post('/_exceptions/not-found')
            ->assertRedirect('/somewhere')
            ->assertInertiaFlash('toast.message', __('common.not_found'));
    });

    it('opens the error page when a visit finds nothing', function () {
        $this->withHeaders(exceptionResponsesInertia())
            ->get('/_exceptions/not-found')
            ->assertStatus(404)
            ->assertJsonPath('component', ExceptionResponses::ERROR_PAGE)
            ->assertJsonPath('props.status', 404);
    });

    it('never answers in JSON, even when it accepts JSON', function () {
        $this->from('/somewhere')
            ->withHeaders([...exceptionResponsesInertia(), 'Accept' => 'application/json'])
            ->get('/_exceptions/forbidden')
            ->assertRedirect('/somewhere');
    });
});

describe('a JSON client', function () {
    it('gets the message and a stable code for each status', function (string $route, int $status, string $code, string $message) {
        $this->getJson("/api/_exceptions/{$route}")
            ->assertStatus($status)
            ->assertExactJson(['message' => $message, 'code' => $code]);
    })->with([
        'a missing entity' => ['not-found', 404, 'not_found', 'This record no longer exists. It may have been removed.'],
        'the policy message' => ['forbidden', 403, 'forbidden', 'Only the owner may do this.'],
        'no message' => ['forbidden-silent', 403, 'forbidden', 'You are not allowed to do this.'],
        'no session' => ['unauthenticated', 401, 'unauthenticated', 'Please sign in to continue.'],
        'an expired token' => ['expired', 419, 'page_expired', 'The page expired. Please try again.'],
        'any other client error' => ['teapot', 418, 'http_418', "I'm a teapot"],
        'a failure' => ['failure', 500, 'server_error', 'Something went wrong on our side. Please try again later.'],
        'maintenance' => ['maintenance', 503, 'service_unavailable', 'The system is under maintenance. Please check back soon.'],
    ]);

    it('answers a path under api/ in JSON without asking for it', function () {
        $this->get('/api/_exceptions/not-found')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    it('answers a web path in JSON when the client asks for it', function () {
        $this->getJson('/_exceptions/not-found')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    it('names no class when route model binding finds nothing', function () {
        $response = $this->getJson('/api/_exceptions/users/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => __('common.not_found'), 'code' => 'not_found']);

        expect($response->getContent())->not->toContain('App\\\\Models');
    });

    it('keeps the validation errors', function () {
        $this->postJson('/api/_exceptions/validated')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['name']);
    });

    it('keeps the headers Laravel set', function () {
        $this->getJson('/api/_exceptions/throttled')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '30')
            ->assertExactJson(['message' => __('common.too_many_requests'), 'code' => 'too_many_requests']);
    });

    it('keeps the trace while debug is on and never the message', function () {
        config(['app.debug' => true]);

        $this->getJson('/api/_exceptions/failure')
            ->assertServerError()
            ->assertJsonPath('code', 'server_error')
            ->assertJsonPath('message', __('common.server_error'))
            ->assertJsonStructure(['exception', 'file', 'line', 'trace']);
    });

    it('answers a refusal the controller caught with 409 and its code', function () {
        $this->postJson('/api/_exceptions/refused')
            ->assertConflict()
            ->assertExactJson(['message' => 'Your balance is too low.', 'code' => 'insufficient_balance']);
    });

    it('keeps the response an HttpResponseException carries', function () {
        $this->getJson('/api/_exceptions/kept')
            ->assertStatus(400)
            ->assertExactJson(['kept' => true]);
    });
});

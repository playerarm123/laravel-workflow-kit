<?php

namespace App\Http\Middleware;

use App\Application\Auth\UserContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Override;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds who is acting for the request (handlers.md). A handler reads it through `UserContext`
 * and never takes it as an argument. A guest binds nothing, so a handler that needs an actor
 * fails loudly instead of acting as nobody.
 *
 * Add to `UserContext`, and to the class bound here, what the project's roles need.
 */
class InitializeUserContext
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $id = (string) $user->getAuthIdentifier();

            app()->bind(UserContext::class, fn (): UserContext => new class($id) implements UserContext
            {
                public function __construct(private readonly string $id) {}

                #[Override]
                public function id(): string
                {
                    return $this->id;
                }
            });
        }

        /**
         * Laravel adds this to every log entry and every queued job the request starts, so an
         * exception never carries who was acting (exceptions.md).
         */
        Context::add('actor_id', $user?->getAuthIdentifier());

        return $next($request);
    }
}

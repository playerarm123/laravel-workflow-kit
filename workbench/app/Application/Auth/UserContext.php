<?php

namespace App\Application\Auth;

/**
 * Who is acting, read by a handler and never passed in (handlers.md). Middleware binds it for
 * each request. The kit writes this file once; add the methods the project's roles need.
 */
interface UserContext
{
    public function id(): string;
}

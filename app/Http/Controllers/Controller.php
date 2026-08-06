<?php

namespace App\Http\Controllers;

use App\Support\ErrorAudience;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Throwable;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Build a client-facing error message. API-token (api role) users receive
     * only the generic message; trusted web users (admin/editor) also see the
     * underlying exception detail. The full exception is logged separately by
     * the caller regardless of role.
     *
     * The role rule itself lives in ErrorAudience, because the global exception
     * handler needs the same rule and cannot reach a protected controller method.
     */
    protected function clientError(Throwable $e, string $generic): string
    {
        return trim($generic.' '.(ErrorAudience::detail($e) ?? ''));
    }
}

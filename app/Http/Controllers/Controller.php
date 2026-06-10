<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Throwable;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Build a client-facing error message. API-token (api role) users receive
     * only the generic message; trusted web users (admin/editor) also see the
     * underlying exception detail. The full exception is logged separately by
     * the caller regardless of role.
     */
    protected function clientError(Throwable $e, string $generic): string
    {
        if (optional(Auth::user())->isApiUser()) {
            return $generic;
        }

        return trim($generic.' '.$e->getMessage());
    }
}

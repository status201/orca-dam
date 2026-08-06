<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stamps the error reference on every handler-rendered error response, and scrubs a 5xx JSON body
 * for an api-role caller.
 *
 * The scrubbing is a safety net, not the fix: `APP_DEBUG=true` is this repository's default in
 * `.env`, `.env.example` and `.env.e2e`, and with debug on Laravel ships file, line and the full
 * stack trace in a JSON 5xx. The real fix is `APP_DEBUG=false` in production; this bounds the
 * damage when it isn't.
 *
 * Note `$exceptions->respond()` is a SINGLE-SLOT registration — a second one anywhere silently
 * replaces this, and the reference disappears from every response. There is no framework guard.
 *
 * @see specs/features/error-handling.md REQ-7, REQ-10
 */
final class ErrorResponseDecorator
{
    public static function decorate(Response $response, Request $request): Response
    {
        try {
            $response->headers->set('X-Orca-Error-Id', ErrorId::current());

            // Everything below is about 5xx JSON bodies only. 422s, redirects and the 404/419
            // pages keep the header and are otherwise untouched — including the inner 422 this
            // runs over a second time when DatabaseErrorResponder re-enters the handler, which is
            // why the whole method has to stay idempotent.
            if ($response->getStatusCode() < 500 || ! $response instanceof JsonResponse) {
                return $response;
            }

            $data = $response->getData(true);

            if (! is_array($data)) {
                return $response;
            }

            if (optional(Auth::user())->isApiUser()) {
                return $response->setData([
                    'message' => __('Something went wrong on our end. Quote reference :id if it keeps happening.', [
                        'id' => ErrorId::current(),
                    ]),
                ]);
            }

            return $response->setData($data + ['error_id' => ErrorId::current()]);
        } catch (Throwable) {
            // A decorator must never become the error it is decorating.
            return $response;
        }
    }
}

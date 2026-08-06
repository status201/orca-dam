<?php

namespace App\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a classified driver rejection into an HTTP response.
 *
 * @see specs/features/error-handling.md REQ-2, REQ-4, REQ-5
 */
final class DatabaseErrorResponder
{
    public function respond(QueryException $e, Request $request): ?Response
    {
        $hint = DatabaseError::classify($e);

        if ($hint === null) {
            // Unrecognised: this is plausibly a real bug, so in development we decline to handle it
            // and the framework's debug page (with the stack trace) still renders. Returning null
            // here is what keeps local debugging workable — do not "simplify" it away.
            if (config('app.debug')) {
                return null;
            }

            return $this->genericFailure($request);
        }

        if ($hint->isKeyed()) {
            // Re-enter the framework's own handler with a real ValidationException instead of
            // hand-building the response. That inherits, exactly and for free: the `dontFlash`
            // list, `_error_bag` handling, the redirect target, and the {message, errors} JSON
            // shape. A hand-rolled back()->withErrors() would have to re-derive all four and would
            // drift from whatever a FormRequest failure does.
            //
            // This does not recurse: renderViaCallbacks dispatches on the closure's first parameter
            // type, so a callback typed QueryException cannot match a ValidationException.
            return app(ExceptionHandler::class)->render(
                $request,
                ValidationException::withMessages([$hint->column => $hint->message])
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $hint->message,
                'error_id' => ErrorId::current(),
            ], $hint->status);
        }

        return back()->withInput()->with('error', $hint->message);
    }

    /**
     * The last resort: something went wrong in the database that we could not name. The user gets no
     * detail (there is none to give that would help) but does get a reference to quote.
     */
    private function genericFailure(Request $request): Response
    {
        $message = __('Something went wrong saving your changes. Quote reference :id if it keeps happening.', [
            'id' => ErrorId::current(),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'error_id' => ErrorId::current(),
            ], 500);
        }

        return back()->withInput()->with('error', $message);
    }
}

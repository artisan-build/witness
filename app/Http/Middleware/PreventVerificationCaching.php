<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class PreventVerificationCaching
{
    public function __construct(private ExceptionHandler $exceptions) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
            $response = $this->exceptions->render($request, $exception);
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

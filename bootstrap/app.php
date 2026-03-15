<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\AllowApiCors::class);

        $middleware->alias([
            'firebase.auth' => \App\Http\Middleware\AuthenticateFirebaseToken::class,
            'firebase.admin' => \App\Http\Middleware\EnsureFirebaseAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $exception, $request) {
            if ($request->is('api/*') || $request->is('sendLoginOTP') || $request->is('verifyLoginOTP')) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ], $exception->status);
            }
        });

        $exceptions->render(function (AuthenticationException $exception, $request) {
            if ($request->is('api/*') || $request->is('sendLoginOTP') || $request->is('verifyLoginOTP')) {
                return response()->json([
                    'message' => $exception->getMessage() ?: 'Unauthenticated.',
                ], 401);
            }
        });

        $exceptions->render(function (\Throwable $exception, $request) {
            if ($request->is('api/*') || $request->is('sendLoginOTP') || $request->is('verifyLoginOTP')) {
                $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

                return response()->json([
                    'message' => $status >= 500 ? 'Server error' : $exception->getMessage(),
                ], $status);
            }
        });
    })->create();

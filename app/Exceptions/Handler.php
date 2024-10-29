<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Intervention\Image\Exception\NotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (\League\OAuth2\Server\Exception\OAuthServerException $e) {
            if($e->getCode() == 9)
                return false;
        });

        $this->reportable(function (Throwable $e) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        });

        $this->renderable(function(Exception $e, $request) {
            return $this->handleException($request, $e);
        });
    }
    
    public function handleException($request, Exception $exception)
    {
        if($exception instanceof NotFoundHttpException) {
            return response(['message' => 'Not Found.'], 404);
        }

        if ($exception instanceof ApiRequestException) {
            return response()->json( $exception->responseBody, 400);
        }
    }
}

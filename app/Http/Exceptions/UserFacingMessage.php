<?php

namespace App\Http\Exceptions;

use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Text shown to the user for an HTTP error (E3.6b). An exception message is shown only when it is a
 * translation key or a framework text with a translation; anything else (technical details, model names,
 * internal codes) is replaced by the translated text of the status.
 */
final class UserFacingMessage
{
    public static function for(HttpExceptionInterface $e): string
    {
        $message = $e->getMessage();
        if ($message !== '' && Lang::has($message)) {
            return __($message);
        }
        $status = $e->getStatusCode();

        return Lang::has('errors.http.'.$status) ? __('errors.http.'.$status) : __(Response::$statusTexts[$status] ?? 'Server Error');
    }
}

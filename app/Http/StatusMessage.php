<?php

namespace App\Http;

use Illuminate\Support\Facades\Lang;

/**
 * Text of the status flashed after an action (E3.10g). Fortify flashes technical codes (e.g.
 * "two-factor-authentication-enabled"); they are shown through `ui.status_codes`, never as the code itself. Our own
 * statuses are already translated sentences and pass through unchanged.
 */
final class StatusMessage
{
    public static function for(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        return Lang::has('ui.status_codes.'.$status) ? __('ui.status_codes.'.$status) : $status;
    }
}

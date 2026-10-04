<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Same answer whether the account exists, does not exist or was throttled, so the form cannot be used
 * to discover accounts (Z-023). Invalid input is still reported by validation.
 */
class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const MESSAGE = 'auth.screens.forgot_password.sent';

    public function __construct(protected string $status = '') {}

    /** @param Request $request */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        return $request->wantsJson()
            ? new JsonResponse(['message' => __(self::MESSAGE)], 200)
            : back()->with('status', __(self::MESSAGE));
    }
}

<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

/**
 * Answers "no such user" and "link already sent recently" exactly like a
 * successful request, so the form cannot be used to find out which email
 * addresses have an account.
 */
class PasswordResetLinkRequestedResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(private readonly string $status) {}

    public function toResponse($request): JsonResponse
    {
        if (in_array($this->status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return new JsonResponse(['message' => trans(Password::RESET_LINK_SENT)], 200);
        }

        return new JsonResponse(['message' => trans($this->status), 'errors' => ['email' => [trans($this->status)]]], 422);
    }
}

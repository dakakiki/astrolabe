<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;
use App\Policies\Concerns\ChecksMembership;
use Illuminate\Auth\Access\Response;

/**
 * Same rules as notes: a private file is seen and downloaded only by whoever
 * uploaded it, and is a 404 for everyone else.
 */
class AttachmentPolicy
{
    use ChecksMembership;

    /** Lists are filtered by visibility in the query (Attachment::scopeVisibleTo). */
    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Attachment $attachment): Response
    {
        return $this->isMember($user) && $this->inCurrentWorkspace($attachment) && $attachment->isVisibleTo($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, Attachment $attachment): Response
    {
        $view = $this->view($user, $attachment);

        if ($view->denied()) {
            return $view;
        }

        return $attachment->uploaded_by === $user->id || $this->isOwner($user)
            ? Response::allow()
            : Response::deny();
    }

    public function delete(User $user, Attachment $attachment): Response
    {
        return $this->update($user, $attachment);
    }
}

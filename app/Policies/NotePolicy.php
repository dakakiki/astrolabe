<?php

namespace App\Policies;

use App\Models\Note;
use App\Models\User;
use App\Policies\Concerns\ChecksMembership;
use Illuminate\Auth\Access\Response;

/**
 * A private note exists only for its author: to anyone else it is a 404, not
 * a 403, so its existence is not revealed. Team and shared notes are read by
 * every member and changed by their author or the workspace owner.
 */
class NotePolicy
{
    use ChecksMembership;

    /** Lists are filtered by visibility in the query (Note::scopeVisibleTo). */
    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Note $note): Response
    {
        return $this->isMember($user) && $this->inCurrentWorkspace($note) && $note->isVisibleTo($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, Note $note): Response
    {
        $view = $this->view($user, $note);

        if ($view->denied()) {
            return $view;
        }

        return $note->created_by === $user->id || $this->isOwner($user)
            ? Response::allow()
            : Response::deny();
    }

    public function delete(User $user, Note $note): Response
    {
        return $this->update($user, $note);
    }
}

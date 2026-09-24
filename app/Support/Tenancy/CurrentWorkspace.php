<?php

namespace App\Support\Tenancy;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

/**
 * The workspace the current request (or job) acts in.
 *
 * Set by the ResolveCurrentWorkspace middleware for HTTP requests, and
 * explicitly via run() for jobs and commands. Tenant queries read it through
 * WorkspaceScope; when nothing is set they return no rows rather than all rows.
 */
class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    /**
     * Roles looked up in this workspace, by user id, so a list of notes does not
     * ask the database once per row. Forgotten whenever the workspace is set.
     *
     * @var array<int, WorkspaceRole|null>
     */
    private array $roles = [];

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
        $this->roles = [];
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->getKey();
    }

    /** The user's active role here, or null when they are not an active member. */
    public function roleOf(User $user): ?WorkspaceRole
    {
        if ($this->workspace === null) {
            return null;
        }

        if (! array_key_exists($user->getKey(), $this->roles)) {
            $this->roles[$user->getKey()] = $user->roleIn($this->workspace);
        }

        return $this->roles[$user->getKey()];
    }

    /**
     * Run a callback inside the given workspace, restoring the previous one afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Workspace $workspace, callable $callback): mixed
    {
        [$previous, $previousRoles] = [$this->workspace, $this->roles];
        $this->set($workspace);

        try {
            return $callback();
        } finally {
            [$this->workspace, $this->roles] = [$previous, $previousRoles];
        }
    }
}

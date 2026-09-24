<?php

namespace App\Support\Tenancy;

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

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->getKey();
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
        $previous = $this->workspace;
        $this->workspace = $workspace;

        try {
            return $callback();
        } finally {
            $this->workspace = $previous;
        }
    }
}

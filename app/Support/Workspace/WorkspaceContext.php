<?php

namespace App\Support\Workspace;

use App\Models\Workspace;
use RuntimeException;

class WorkspaceContext
{
    private ?Workspace $current = null;

    private ?Workspace $default = null;

    /**
     * Returns the current workspace for this request / process.
     *
     * MVP simplification: always resolves to the default workspace while only one tenant exists.
     *
     * @todo GAP-004 — resolve workspace from authenticated User (admin) or Customer (cabinet),
     *       and from scheduled sync commands that run outside HTTP/guard context.
     */
    public function current(): Workspace
    {
        return $this->current ??= $this->default();
    }

    public function id(): string
    {
        return $this->current()->id;
    }

    public function default(): Workspace
    {
        if ($this->default !== null) {
            return $this->default;
        }

        $defaults = Workspace::query()->where('is_default', true)->get();

        if ($defaults->isEmpty()) {
            throw new RuntimeException('No default workspace found. Exactly one workspace must have is_default = true.');
        }

        if ($defaults->count() > 1) {
            throw new RuntimeException('Multiple default workspaces found. Exactly one workspace must have is_default = true.');
        }

        return $this->default = $defaults->first();
    }

    public function reset(): void
    {
        $this->current = null;
        $this->default = null;
    }
}

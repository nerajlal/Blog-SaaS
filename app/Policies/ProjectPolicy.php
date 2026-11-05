<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        if (!$project->workspace_id) {
            return $project->user_id === $user->id;
        }

        return $user->workspaces()->whereKey($project->workspace_id)->exists();
    }

    public function update(User $user, Project $project): bool
    {
        if (!$project->workspace_id) {
            return $project->user_id === $user->id;
        }

        return $this->canWrite($user, $project->workspace_id);
    }

    public function manageSeo(User $user, Project $project): bool
    {
        if (!$project->workspace_id) {
            return $project->user_id === $user->id;
        }
        return $user->workspaces()->whereKey($project->workspace_id)->wherePivotIn('role', ['owner', 'admin'])->exists();
    }

    public function createInWorkspace(User $user, Workspace $workspace): bool
    {
        return $this->canWrite($user, $workspace->id);
    }

    private function canWrite(User $user, int $workspaceId): bool
    {
        return $user->workspaces()->whereKey($workspaceId)
            ->wherePivotIn('role', ['owner', 'admin', 'editor'])->exists();
    }
}

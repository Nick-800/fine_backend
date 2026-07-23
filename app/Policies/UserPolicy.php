<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    /**
     * Determine whether the user can view any users.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('owner') || $user->hasPermission('manage-users') || $user->hasPermission('view-users');
    }

    /**
     * Determine whether the user can view the specific user.
     */
    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->hasRole('owner') || $user->hasPermission('manage-users') || $user->hasPermission('view-users');
    }

    /**
     * Determine whether the user can create users.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('owner') || $user->hasPermission('manage-users') || $user->hasPermission('create-users');
    }

    /**
     * Determine whether the user can update the user.
     */
    public function update(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->hasRole('owner') || $user->hasPermission('manage-users') || $user->hasPermission('edit-users');
    }

    /**
     * Determine whether the user can delete the user.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->hasRole('owner') || $user->hasPermission('manage-users') || $user->hasPermission('delete-users');
    }
}

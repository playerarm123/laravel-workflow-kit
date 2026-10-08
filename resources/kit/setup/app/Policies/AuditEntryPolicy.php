<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may read the audit log. The model names it with #[UsePolicy(AuditEntryPolicy::class)].
 *
 * Read only: the log is append only (audit-log.md), so there is nothing to create, change or
 * delete, and every entry is shown in the list itself, so no ability asks about one entry.
 */
class AuditEntryPolicy
{
    /**
     * Every user who verified their email, until the project names its own readers: the log
     * shows what every user did, so narrow this to the roles that may see it.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }
}

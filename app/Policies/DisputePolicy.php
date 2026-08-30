<?php

// app/Policies/DisputePolicy.php

namespace App\Policies;

use App\Models\Dispute;
use App\Models\User;

class DisputePolicy
{
    // Redundant with the 'admin' route middleware already guarding every
    // AdminDisputeController route — kept as an explicit, independently
    // testable statement of the rule rather than relying solely on route
    // wiring to enforce it.
    public function resolve(User $user, Dispute $dispute): bool
    {
        return $user->isAdmin();
    }
}

<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

/**
 * Admin-only, full stop — a landlord's own tickets are scoped and acted on
 * through SupportTicketService directly (landlord_id = Auth::id()), not
 * through this policy. There's no manager delegation here at all: an
 * escalation to the platform is the landlord's own account-level concern,
 * same reasoning Settings is landlord-only.
 */
class SupportTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, SupportTicket $ticket): bool
    {
        return $user->hasRole('admin');
    }
}

<?php

namespace App\Console\Commands;

use App\Models\TenantInvitation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tenant-invitations:expire')]
#[Description('Mark pending/opened tenant invitations past their expiry as expired, and free up any unit they reserved.')]
class ExpireTenantInvitations extends Command
{
    public function handle(): int
    {
        $expired = 0;

        TenantInvitation::whereIn('status', ['pending', 'opened'])
            ->where('expires_at', '<=', now())
            ->with('house')
            ->chunkById(200, function ($invitations) use (&$expired) {
                foreach ($invitations as $invitation) {
                    $invitation->update(['status' => 'expired']);

                    if ($invitation->house->status === 'reserved') {
                        $invitation->house->update(['status' => 'vacant']);
                    }

                    $expired++;
                }
            });

        $this->info("Expired {$expired} tenant invitation(s).");

        return self::SUCCESS;
    }
}

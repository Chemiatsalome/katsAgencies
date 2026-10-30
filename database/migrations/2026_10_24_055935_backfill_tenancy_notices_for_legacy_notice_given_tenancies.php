<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

return new class extends Migration
{
    /**
     * Before the structured notice-tracking feature, "Give Notice" only
     * ever set tenancies.status/notice_date directly — no TenancyNotice row
     * was ever created. Any tenancy already sitting at 'notice_given' from
     * that old flow would otherwise show "Notice Given" in its own status
     * tile while the new Notices panel (and the tenant's Tenancy Notice
     * card, which only renders when a notice row exists) both say there's
     * nothing there — the two views disagreeing about the same tenancy.
     * This backfills exactly one TenancyNotice per such tenancy so both
     * sides read the same thing going forward.
     */
    public function up(): void
    {
        $tenancies = DB::table('tenancies')
            ->where('status', 'notice_given')
            ->whereNotNull('notice_date')
            ->get();

        foreach ($tenancies as $tenancy) {
            $alreadyHasNotice = DB::table('tenancy_notices')->where('tenancy_id', $tenancy->id)->exists();

            if ($alreadyHasNotice) {
                continue;
            }

            $noticeDate = Carbon::parse($tenancy->notice_date);
            $proposedVacateDate = $tenancy->notice_period_days
                ? $noticeDate->copy()->addDays($tenancy->notice_period_days)->format('Y-m-d')
                : null;

            $noticeId = DB::table('tenancy_notices')->insertGetId([
                'tenancy_id' => $tenancy->id,
                'sequence_number' => 1,
                'reason_category' => 'other',
                'reason_details' => 'Backfilled — this notice was issued before the structured notice-tracking feature existed.',
                'notice_date' => $noticeDate->format('Y-m-d'),
                'notice_period_days' => $tenancy->notice_period_days,
                'proposed_vacate_date' => $proposedVacateDate,
                'status' => 'issued',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('tenancy_notice_events')->insert([
                'tenancy_notice_id' => $noticeId,
                'event_type' => 'issued',
                'note' => 'Backfilled from the tenancy\'s pre-existing notice_given status.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Deliberately not reversed — there's no reliable way to tell a
        // backfilled notice apart from a genuine one created afterward.
    }
};

<?php

namespace App\Services;

use App\Mail\GenericNotificationMail;
use App\Models\AppNotification;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

class NotificationService
{
    /**
     * @param  Model  $notifiable  Typically a Tenant or User — must expose ->email for the email channel.
     * @param  string[]  $channels  Any of: in_app, email, sms.
     */
    public function notify(
        Model $notifiable,
        string $eventType,
        string $title,
        string $body,
        array $channels = ['in_app'],
        ?Model $related = null,
    ): void {
        foreach ($channels as $channel) {
            $notification = AppNotification::create([
                'notifiable_type' => $notifiable->getMorphClass(),
                'notifiable_id' => $notifiable->getKey(),
                'channel' => $channel,
                'event_type' => $eventType,
                'title' => $title,
                'body' => $body,
                'status' => 'pending',
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
                // G-1A: delivery/billing tracking is sms-only — left null
                // for in_app/email, which never read these columns.
                'delivery_status' => $channel === 'sms' ? 'pending' : null,
                'billing_status' => $channel === 'sms' ? 'pending' : null,
            ]);

            $this->deliver($notification, $notifiable);
        }
    }

    /**
     * G-1A: re-enters delivery for an sms notification that already exists
     * — either to resume billing for one already confirmed sent
     * (delivery_status='sent', billing_status still 'pending', e.g. after a
     * crash between provider acceptance and the wallet debit), or to retry
     * one that's still genuinely 'pending' (never attempted, or a safe
     * pre-submission failure). Never re-contacts the provider for a
     * notification whose delivery outcome is already resolved —
     * deliverSms() itself enforces that; this is just a public entry point
     * onto the same logic notify() uses, for the reconciliation command and
     * for manual retries.
     */
    public function resume(AppNotification $notification): void
    {
        if ($notification->channel !== 'sms' || ! $notification->notifiable) {
            return;
        }

        $this->deliverSms($notification, $notification->notifiable);
    }

    /**
     * G-1A: an admin's evidence-based resolution of a notification whose
     * delivery outcome Africa's Talking never confirmed — never an
     * automated guess, and never usable to manufacture a confirmed
     * delivery out of nothing. $note is the evidence the admin is
     * attesting to (e.g. "checked Africa's Talking's own dashboard logs,"
     * "tenant confirmed receipt by phone"), recorded on the row and, via
     * AppNotification's LogsActivity, in the existing activity log
     * alongside who made the call and when.
     *
     * Deliberately narrow: only ever acts on a notification still
     * genuinely `submission_unknown`, re-checked under a row lock so two
     * admins (or a double-click) resolving the same stale notification
     * can't both succeed — a row already resolved by someone else, or
     * already past this state for any other reason (e.g. the automatic
     * reconciliation sweep got to it first), throws rather than silently
     * overwriting it. A `reconciliation_required` row whose delivery was
     * already confirmed `sent` (billing failed after a genuine send) has
     * nothing ambiguous left to resolve this way — that discrepancy is
     * already an auditable, honest ledger entry (see
     * SmsWalletService::recordUnbilledSend()), never touched here.
     *
     * 'confirmed_sent' never bills here directly and never re-contacts the
     * provider — it hands billing to the exact same automatic recovery
     * path every other confirmed send uses (resume()/settleBilling()),
     * called only once the state-guarded update below has actually
     * committed.
     */
    public function reconcileManually(AppNotification $notification, User $admin, string $resolution, string $note): AppNotification
    {
        if (! $admin->hasRole('admin')) {
            throw new AuthorizationException('Only an administrator can take this action.');
        }

        if (trim($note) === '') {
            throw new InvalidArgumentException('A note describing the evidence for this decision is required.');
        }

        if (! in_array($resolution, ['confirmed_sent', 'confirmed_not_sent'], true)) {
            throw new InvalidArgumentException('Unknown resolution action.');
        }

        $locked = DB::transaction(function () use ($notification, $admin, $resolution, $note) {
            $locked = AppNotification::where('id', $notification->id)->lockForUpdate()->first();

            if (! $locked || $locked->channel !== 'sms') {
                throw new InvalidArgumentException('Notification not found.');
            }

            if ($locked->delivery_status !== 'submission_unknown') {
                throw new InvalidArgumentException('This notification is no longer awaiting reconciliation — refresh and check its current state.');
            }

            $locked->update($resolution === 'confirmed_sent'
                ? [
                    'status' => 'sent', 'sent_at' => now(), 'delivery_status' => 'sent', 'billing_status' => 'pending',
                    'reconciliation_note' => $note, 'reconciled_at' => now(), 'reconciled_by' => $admin->id,
                ]
                : [
                    'status' => 'failed', 'delivery_status' => 'failed', 'billing_status' => 'not_chargeable',
                    'reconciliation_note' => $note, 'reconciled_at' => now(), 'reconciled_by' => $admin->id,
                ]
            );

            return $locked;
        });

        if ($resolution === 'confirmed_sent') {
            $this->resume($locked->refresh());
        }

        return $locked->refresh();
    }

    /**
     * Marks the originating alert read once whoever it was for has acted on
     * it — a decision, a reply, a status change — so the generic
     * notification bell stops counting something already resolved.
     * Filtered by notifiable as well as the related record, since several
     * event types can share the same related model (e.g. a tenancy) from
     * both directions — without that filter, resolving one party's alert
     * would also silently mark the other party's still-unread notification
     * as read. $eventType narrows further for a related model that's shared
     * across many unrelated event types (e.g. Tenancy itself).
     */
    public function markRelatedRead(Model $related, Model $notifiable, ?string $eventType = null): void
    {
        AppNotification::where('related_type', $related->getMorphClass())
            ->where('related_id', $related->getKey())
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->when($eventType, fn ($q) => $q->where('event_type', $eventType))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function deliver(AppNotification $notification, Model $notifiable): void
    {
        match ($notification->channel) {
            'in_app' => $notification->update(['status' => 'sent', 'sent_at' => now()]),
            'email' => $this->deliverEmail($notification, $notifiable),
            'sms' => $this->deliverSms($notification, $notifiable),
            default => null,
        };
    }

    private function deliverEmail(AppNotification $notification, Model $notifiable): void
    {
        $email = $this->resolveEmailAddress($notifiable);

        if (! $email) {
            $notification->update(['status' => 'failed']);

            return;
        }

        try {
            Mail::to($email)->send(new GenericNotificationMail($notification->title, $notification->body));
            $notification->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Notification email failed', ['notification_id' => $notification->id, 'error' => $e->getMessage()]);
            $notification->update(['status' => 'failed']);
        }
    }

    /**
     * Phase 7B follow-up: tenants.email is an optional, landlord-entered
     * contact field, entirely separate from — and never synced with — the
     * tenant's actual portal-login email (tenants.user.email, set only by
     * TenancyProvisioningService::createPortalAccount()). Reading
     * $notifiable->email directly meant a tenant with working portal access
     * but no separately-entered contact email silently received zero
     * email-channel notifications, ever. Scoped strictly to Tenant — every
     * other notifiable type (User, covering landlords/managers/admins)
     * resolves exactly as before, unchanged.
     */
    private function resolveEmailAddress(Model $notifiable): ?string
    {
        if (! $notifiable instanceof Tenant) {
            return $notifiable->email ?? null;
        }

        return $this->usableEmail($notifiable->email) ?? $this->usableEmail($notifiable->user?->email);
    }

    /** Null for blank/whitespace-only/malformed input — never attempts to send to something that isn't a real address. */
    private function usableEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * G-2/G-1A: fails closed at every step — SmsService::send() is never
     * reached unless a real, billable landlord with a sufficient balance
     * has already been established, and never reached twice for the same
     * notification. Delivery (did it reach the provider?) and billing (did
     * the wallet get charged?) are tracked independently via
     * delivery_status/billing_status; the existing `status` column keeps
     * exactly its old meaning throughout, for every caller that already
     * reads it.
     *
     * The G-1A investigation found no reliable provider-side idempotency
     * key or after-the-fact query for Africa's Talking, and confirmed (via
     * Guzzle's own curl-error classification) that an HTTP exception here
     * cannot be reliably distinguished into "definitely never reached the
     * provider" vs "reached it, response lost" — so every exception is
     * `submission_unknown`, never auto-retried. A notification already
     * confirmed `sent` with billing still `pending` resumes billing only,
     * never a second provider call — see the early-return below.
     */
    private function deliverSms(AppNotification $notification, Model $notifiable): void
    {
        $wallet = app(SmsWalletService::class);

        // Resume path, checked first and independent of phone/landlord
        // re-resolution below: the message already, confirmedly, went out
        // — never re-contact the provider just because billing didn't
        // finish last time (a crash between acceptance and the debit, or a
        // prior resume() call that itself failed before reaching this
        // point). Re-resolving the landlord here (rather than trusting
        // whatever was billable last time) is deliberate — it's the same
        // resolution notify() would have used, just repeated.
        if ($notification->delivery_status === 'sent' && $notification->billing_status === 'pending') {
            $landlordId = $this->resolveBillableLandlordId($notifiable, $notification->related);

            if ($landlordId !== null) {
                $wallet->settleBilling($landlordId, $wallet->rate(), $notification);
            }

            return;
        }

        // Anything else already past 'pending' is a notification resume()
        // has no business re-processing (failed/not_chargeable is
        // terminal; submission_unknown awaits reconciliation, not an
        // automatic retry; submitting is either a live claim or something
        // the reconciliation sweep will expire).
        if ($notification->delivery_status !== null && $notification->delivery_status !== 'pending') {
            return;
        }

        $phone = $notifiable->phone ?? null;

        if (! $phone) {
            $notification->update(['status' => 'failed', 'delivery_status' => 'failed', 'billing_status' => 'not_chargeable']);

            return;
        }

        $landlordId = $this->resolveBillableLandlordId($notifiable, $notification->related);

        if ($landlordId === null) {
            $notification->update(['status' => 'failed', 'delivery_status' => 'failed', 'billing_status' => 'not_chargeable']);

            return;
        }

        $rate = $wallet->rate();

        // Cheap pre-check before even attempting a send — an empty wallet
        // means SMS just silently stops (in-app/email, if also selected,
        // are unaffected — each channel is its own AppNotification row via
        // the loop in notify() above). The landlord sees their balance in
        // Settings; the tenant is never told a text didn't arrive over a
        // billing issue that isn't theirs.
        if ($wallet->balance($landlordId) < $rate) {
            $notification->update(['status' => 'failed', 'delivery_status' => 'failed', 'billing_status' => 'not_chargeable']);

            return;
        }

        // Atomic claim: pending -> submitting. The WHERE clause is the
        // concurrency guard — under real concurrent load this is enforced
        // by the database's own row handling during the UPDATE, not by
        // anything checked in PHP first. Exactly one concurrent caller (or,
        // once this is ever queued, exactly one job attempt) affects a
        // row; every other simultaneous caller gets 0 and must never touch
        // the provider.
        $token = (string) Str::uuid();
        $claimed = AppNotification::where('id', $notification->id)
            ->where('delivery_status', 'pending')
            ->update([
                'delivery_status' => 'submitting',
                'submission_token' => $token,
                'submission_started_at' => now(),
                'submission_attempt' => DB::raw('submission_attempt + 1'),
            ]);

        if ($claimed === 0) {
            // Someone else already claimed (or resolved) this notification
            // — never call the provider ourselves.
            return;
        }

        // The title is dropped here on purpose — unlike email/in-app, an
        // SMS has no separate subject line, and prepending it would just
        // eat into the 160-character budget for no benefit.
        $outcome = app(SmsService::class)->send($phone, $notification->body);

        // Every write from here on is guarded by the token we were just
        // handed, not just the row id — if the reconciliation sweep
        // expired our claim while this HTTP call was in flight (lease
        // exceeded) and reassigned/resolved the row, our token no longer
        // matches and this affects 0 rows; we must not act on billing for
        // an outcome the row no longer attributes to us.
        $resolved = match ($outcome) {
            'accepted' => AppNotification::where('id', $notification->id)->where('submission_token', $token)
                ->update(['status' => 'sent', 'sent_at' => now(), 'delivery_status' => 'sent']),
            'rejected' => AppNotification::where('id', $notification->id)->where('submission_token', $token)
                ->update(['status' => 'failed', 'delivery_status' => 'failed', 'billing_status' => 'not_chargeable']),
            // Never resubmitted automatically — only ever leaves this state
            // via the reconciliation command, with human-supplied evidence.
            'unknown' => AppNotification::where('id', $notification->id)->where('submission_token', $token)
                ->update(['delivery_status' => 'submission_unknown', 'billing_status' => 'reconciliation_required']),
        };

        if ($resolved === 0 || $outcome !== 'accepted') {
            return;
        }

        // debit() re-checks the balance under a row lock — the cheap check
        // above is only a pre-filter to avoid attempting a send we already
        // know can't be billed; a concurrent debit between that check and
        // this one can still legitimately exhaust the balance by now.
        // settleBilling() commits the wallet entry and this notification's
        // billing_status together, so a crash between the two is
        // impossible — see SmsWalletService::settleBilling().
        $notification->refresh();
        $wallet->settleBilling($landlordId, $rate, $notification);
    }

    /**
     * Which landlord's SMS wallet pays for this message.
     *
     * Tenant: the tenancy this notification is actually about — via
     * $related, when it safely identifies one that genuinely belongs to
     * this same tenant — takes priority over the tenant's current
     * tenancy. A reminder about a tenancy that's since ended, or that
     * differs from whichever one happens to be active right now, must
     * still bill the landlord it was actually about; falling through to
     * an unrelated (or absent) current tenancy would silently bypass
     * billing or charge the wrong landlord. $related belonging to a
     * different tenant entirely is never trusted, to avoid inventing an
     * association this notification was never about.
     *
     * Landlord/manager User: User::landlordId() already resolves exactly
     * this — themselves, or their delegating landlord.
     *
     * Admin, any other User landlordId() can't resolve, or any other
     * notifiable type entirely: unbillable, by design — never invented.
     */
    private function resolveBillableLandlordId(Model $notifiable, ?Model $related): ?int
    {
        if ($notifiable instanceof Tenant) {
            $tenancy = $this->resolveRelatedTenancy($related, $notifiable) ?? $notifiable->currentTenancy();

            return $tenancy?->house?->block?->property?->landlord_id;
        }

        if ($notifiable instanceof User) {
            return $notifiable->landlordId();
        }

        return null;
    }

    /**
     * $related can be practically any model across the ~80 notify() call
     * sites in the app (Message, Complaint, DepositDeduction, and so on) —
     * only a Tenancy itself, or something with its own tenancy() relation
     * (e.g. RentCharge, the only kind sms-channel callers currently pass),
     * is ever treated as identifying one; anything else, or a tenancy
     * that resolves but belongs to a different tenant, is ignored rather
     * than guessed at.
     */
    private function resolveRelatedTenancy(?Model $related, Tenant $tenant): ?Tenancy
    {
        $tenancy = match (true) {
            $related instanceof Tenancy => $related,
            $related !== null && method_exists($related, 'tenancy') => $related->tenancy,
            default => null,
        };

        return $tenancy instanceof Tenancy && $tenancy->tenant_id === $tenant->id ? $tenancy : null;
    }
}

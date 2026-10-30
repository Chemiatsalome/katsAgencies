<?php

namespace App\Livewire\ContractTemplates;

use App\Models\ContractTemplate;
use App\Models\LandlordSetting;
use App\Models\Property;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Form extends Component
{
    public ?ContractTemplate $template = null;

    public string $name = '';

    public string $body = '';

    public bool $is_default = false;

    /** Empty string = "All Properties" (the landlord-wide fallback) — kept a string, not nullable int, so the <select> has a plain '' option value to bind to. */
    public string $property_id = '';

    /** @var array<string,string> Shown in the UI as a click-to-copy cheat sheet. */
    public const PLACEHOLDERS = [
        '{{tenant_name}}' => "Tenant's full name",
        '{{tenant_id_number}}' => "Tenant's ID/passport number",
        '{{tenant_phone}}' => "Tenant's phone number",
        '{{tenant_email}}' => "Tenant's email address",
        '{{property_name}}' => 'Property name',
        '{{property_address}}' => 'Property address',
        '{{block_name}}' => 'Block name',
        '{{unit_number}}' => 'Unit/house number',
        '{{monthly_rent}}' => 'Monthly rent amount',
        '{{deposit_amount}}' => 'Deposit amount',
        '{{tenancy_start_date}}' => 'Move-in date',
        '{{tenancy_end_date}}' => 'Lease end date (or "Open-ended")',
        '{{rent_due_day}}' => "Day rent is due, e.g. \"5th\" (uses this tenancy's actual due date)",
        '{{late_fee_percentage}}' => 'Late fee percentage charged on overdue rent (from Settings → Late Fees)',
        '{{notice_period_days}}' => 'Notice period, in days, required to vacate',
        '{{agreement_date}}' => 'Date the agreement is generated',
        '{{payment_instructions}}' => "How rent should be paid (uses your M-Pesa Paybill/Till from Settings if configured)",
        '{{landlord_name}}' => "Landlord's full name",
        '{{landlord_phone}}' => "Landlord's phone number",
        '{{landlord_email}}' => "Landlord's email address",
        '{{landlord_address}}' => "Landlord's postal/physical address (from their Profile)",
        '{{agency_name}}' => 'Managing agency/company name (from Settings)',
        '{{supervisor_name}}' => 'Complaints/supervisor contact name (from Settings)',
        '{{supervisor_phone}}' => 'Complaints/supervisor contact phone (from Settings)',
        '{{drawn_by_name}}' => 'Name of the person who drew up the agreement (from Settings)',
    ];

    /**
     * A ready-to-use starting point derived from a standard Kenyan tenancy
     * agreement, with the variable details converted to placeholders. Only
     * ever copied into the $body textarea on explicit landlord action
     * (useDefaultTemplate()) — nothing is persisted until they review it and
     * click Save, same as every other explicit-action pattern in this app.
     */
    public const DEFAULT_TEMPLATE_BODY = <<<'TEXT'
TENANCY AGREEMENT

This Agreement is made on {{agreement_date}} between:

a) The Landlord/Lessor
Name: {{landlord_name}}
Address: {{landlord_address}}
Tel: {{landlord_phone}}
Email: {{landlord_email}}

Within the Republic of Kenya, herein referred to as the lessor, which expression shall include their personal representatives, assigns and successors where the context admits, on the one part; and,

b) The Tenant/Lessee
Name: {{tenant_name}}
ID/Passport No: {{tenant_id_number}}
Tel: {{tenant_phone}}
Email: {{tenant_email}}

Within the Republic of Kenya, herein referred to as the lessee, which expression shall include their personal representatives, assigns and successors where the context admits, on the other part.

1: TERMS OF OCCUPANCY AND NOTICE PERIOD

1(a): Condition Assessment and Occupancy
Prior to moving in, the tenant agrees to conduct a joint inspection with the landlord to assess the condition of the property. Upon readiness to occupy the space, the tenant is required to pay a one-month deposit along with the rent. This deposit protects against damages or breaches during the tenancy, facilitating dispute resolution. After 6 months of occupancy, tenants cover repair costs, with technicians provided by the landlord. Deposits are forfeited for occupancy periods under 6 months.

1(b): Damage and Deposit
Any damage incurred during the tenancy will be repaired at the expense of the tenant, either through deductions from the security deposit or through mutually agreed-upon arrangements. Both parties will sign a checklist during the initial inspection to document the property's condition.

1(c): Deposit and Move-Out Procedure
Should the tenant decide to vacate the premises, they must provide the landlord with {{notice_period_days}} days' written notice. Similarly, the landlord must give the tenant the same notice period if repossession of the property is necessary, ensuring the property is returned in its original condition. The one-month deposit serves to facilitate the moving process. Upon giving notice, if painting is required, the landlord must be informed in advance, and the cost will be deducted from the deposit based on prevailing rates.

2: WHEREAS

2(a): Location
The lessor/landlord has agreed to lease to the tenant/lessee Housing Unit No. {{unit_number}} at {{property_name}}, located at {{property_address}}.

2(b): Rent
i) Rent of Kshs. {{monthly_rent}} per month is due monthly, payable via {{payment_instructions}}.
ii) Rent received after the {{rent_due_day}} of every month will incur a surcharge of {{late_fee_percentage}}% of rent and must be settled before the due date to avoid additional charges, which shall be construed as additional rent under Cap 293.
iii) A deposit of Kshs. {{deposit_amount}} is required, in addition to the first month's rent of Kshs. {{monthly_rent}} before occupying the premises.

3: THIS AGREEMENT WITNESSETH AS FOLLOWS

The tenant/lessee hereby covenants with the landlord as follows:
a) To pay rent on the specified date and any other outgoing payments due for the demised premises during the tenancy or business term.
b) Not to assign, change, underlet, sublet, or part with possession of the property without the prior approval or covenant of the landlord.

4: TERMS AND CONDITIONS OF THE TENANCY AGREEMENT

The tenancy agreement encompasses the following terms and conditions:
a) Disposal of waste must be conducted in a responsible manner that does not adversely affect the environment or the premises of the house.
b) Avoid engaging in actions that may annoy or displease fellow tenants, including but not limited to:
   i. Playing excessively loud music;
   ii. Using abusive or rude language, and refraining from engaging in immoral or questionable behavior;
   iii. Conducting activities within the confines of the law, including refraining from selling illicit drinks and drugs.
c) Preserve the utilities provided within the house, including the walls both inside and outside.
d) Promptly report any damaged locks, taps, or sinks to prevent further damage.
e) Ensure proper use of electrical equipment, with no additional alterations made without the express authorization of the landlord.

NOTE: The house rent may be subject to change when necessary, due to the cost of living and other legitimate factors. However, the tenant will be informed in advance of any such change. A minimum notice period of three months will be provided before the changes take effect. This contract is subject to renewal after every 12 months (1 year), accompanied by a routine inspection.

5: MAINTENANCE

If damage is caused by the tenant, their dependents, or any other party associated with them, the landlord reserves the right to request reimbursement for the cost of repairs.

Repairs will be carried out by the landlord's designated repairman, or by a certified technician upon prior mutual agreement between the landlord and the tenant. Minor repairs resulting from aging or normal wear and tear will be managed by the landlord, although decisions regarding such repairs are at the discretion of the landlord. The tenant is expected to exercise organization and responsibility to prevent unnecessary damage.

6: SECURITY

The house is equipped with security measures for the safety of tenants.

7: VEHICLES

Tenants are permitted to park their vehicles only within the perimeter of the property. Any foreign vehicle to be parked within the compound for an extended period must be notified to the landlord in advance by the respective tenant. Tenants are required to provide the landlord with the registration details of any vehicles they own. Parking spaces may be assigned to tenants to ensure organized and efficient use of parking areas. Overnight parking of vehicles belonging to non-residents or guests is prohibited without prior approval from the landlord. All vehicles or any other motor are stored/parked at the owner's risk.

8: EMERGENCY PROCEDURES

In the event of an attack on a housing unit, the affected tenant (referred to as the victim) must promptly notify their fellow tenants to inform them of the situation. If feasible, the victim should also contact the police using their mobile phone. It is essential to collect and maintain the mobile numbers of all tenants for effective communication during emergencies. Additionally, all tenants are advised to ensure their mobile phones are always charged and topped up.

9: COMPLAINTS

To address any concerns or complaints regarding the property, tenants should inform {{agency_name}}. The supervisor overseeing the property is {{supervisor_name}}. For assistance, please contact {{supervisor_phone}}.

10: COVENANT

a) The contract must be signed and handed over within 3 days of commitment after being read and fully understood.
b) The landlord/lessor hereby covenants with the tenant as follows:
   i. THAT the tenant paying the rent herein reserved and observing the covenants on their part shall enjoy peaceful possession of the premises during the tenancy without disturbance on the part of the landlord.
   ii. PROVIDED ALWAYS, and it is hereby agreed by and between the parties hereto, that if the rent herein reserved or any part thereof shall remain unpaid for a period of one (1) month after becoming due and payable, whether demanded or not, or if any covenant on the part of the tenant or the landlord shall not be performed, or either party be wound up, then the landlord may give a notice of two (2) weeks to determine the lease and shall thereupon re-enter the premises, and the tenancy shall be absolutely determined.
c) Please read the agreement thoroughly before signing.
d) The tenancy agreement will be deemed to have been duly executed on the receipt of payment of one month's deposit and rent, or part payment of the same, even where the tenant has not yet signed the agreement.
e) It is gross misconduct to stay in the premises without signing this agreement for a period exceeding 3 days. In such a case, the covenant between the two parties would have been broken; it will be deemed null and void, and it will be left to the discretion of the landlord to take corrective action or give the tenant notice to vacate, forfeiting all initial payments.

In reference to the registered Land Act Cap 300, Laws of Kenya.

Please attach a copy of your ID/Passport as a document to your tenant profile.

EXIT POLICY

If a tenant wants to move, they will give {{notice_period_days}} days' notice as initially agreed by the parties, for both the tenant and the landlord.

A tenant who decides to leave in less than 6 months after moving into the premises will forfeit their deposit. This also includes a tenant who wishes to vacate the premises without notice; they will also forfeit their deposit.

EXECUTION

This agreement is executed electronically. By signing through the tenant portal, the tenant confirms they have read, understood and agree to be bound by the terms above. The tenant's typed full name, the date and time of signing, and the originating IP address are recorded as the tenant's legal signature on this agreement.

Landlord: {{landlord_name}}
Drawn by: {{drawn_by_name}}
TEXT;

    public function useDefaultTemplate(): void
    {
        $this->body = self::DEFAULT_TEMPLATE_BODY;
    }

    public function mount(?ContractTemplate $template = null): void
    {
        if ($template?->exists) {
            $this->authorize('update', $template);
            $this->template = $template;
            $this->name = $template->name;
            $this->body = $template->body;
            $this->is_default = $template->is_default;
            $this->property_id = $template->property_id ? (string) $template->property_id : '';
        } else {
            $this->authorize('create', ContractTemplate::class);
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
        ]);

        $propertyId = $data['property_id'] ?: null;
        unset($data['property_id']);

        $landlordId = $this->template->landlord_id ?? Auth::id();

        if ($propertyId && ! Property::accessibleBy(Auth::user())->whereKey($propertyId)->exists()) {
            abort(403);
        }

        DB::transaction(function () use ($data, $landlordId, $propertyId) {
            // Scoped to the same property (or the same landlord-wide
            // fallback slot when property_id is null) — a default set for
            // one property must never silently demote another property's
            // own default, or the portfolio-wide fallback.
            if ($this->is_default) {
                ContractTemplate::where('landlord_id', $landlordId)
                    ->where(fn ($q) => $propertyId ? $q->where('property_id', $propertyId) : $q->whereNull('property_id'))
                    ->when($this->template, fn ($q) => $q->where('id', '!=', $this->template->id))
                    ->update(['is_default' => false]);
            }

            $data += ['property_id' => $propertyId, 'is_default' => $this->is_default];

            if ($this->template) {
                $this->template->update($data);
            } else {
                ContractTemplate::create($data + ['landlord_id' => $landlordId]);
            }
        });

        session()->flash('status', 'Contract template saved.');
        $this->redirectRoute('manage.contract-templates.index', navigate: true);
    }

    public function render()
    {
        $landlordId = $this->template->landlord_id ?? Auth::id();

        return view('livewire.contract-templates.form', [
            'placeholders' => self::PLACEHOLDERS,
            'properties' => Property::accessibleBy(Auth::user())->orderBy('name')->get(['id', 'name']),
            // {{drawn_by_name}} renders blank in a generated contract until
            // this is set — worth flagging here, not just in Settings,
            // since a landlord filling in this template has no reason to
            // already know that field lives somewhere else.
            'drawnByNameMissing' => blank(LandlordSetting::get($landlordId, 'contract_drawn_by_name')),
        ]);
    }
}

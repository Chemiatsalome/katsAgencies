<?php

namespace Database\Seeders\Portfolios;

use App\Models\DepositPayment;
use App\Models\Property;

class MbungoniBombululuSeeder extends PortfolioSeeder
{
    public function run(): void
    {
        $property = $this->property('Mbungoni Bombolulu', 'Bombolulu, Mombasa');

        $this->blockA($property);
        $this->blockB($property);
        $this->blockC($property);
        $this->blockD($property);
    }

    private function blockA(Property $property): void
    {
        $block = $this->block($property, 'Block A');

        // House 1 — Anne Murangiri
        $house = $this->house($block, '1', 'other', 14000, 14000);
        $t = $this->moveIn($house, ['name' => 'Anne Murangiri', 'phone' => '0708307137'], '2024-01-01', 14000, 14000);
        $this->applySchedule($t, array_fill_keys(range(1, 9), ['paid' => 14000]));

        // House 2 — Samuel Ngatia
        $house = $this->house($block, '2', 'other', 15000, 15000);
        $t = $this->moveIn($house, ['name' => 'Samuel Ngatia', 'phone' => '0705760057'], '2024-01-01', 15000, 15000);
        $this->applySchedule($t, array_fill_keys(range(1, 10), ['paid' => 15000]));

        // House 3 — Collins Agalla
        $house = $this->house($block, '3', 'other', 14000, 14000);
        $t = $this->moveIn($house, ['name' => 'Collins Agalla', 'phone' => '0722417035'], '2024-01-01', 14000, 14000);
        $this->applySchedule($t, array_fill_keys(range(1, 9), ['paid' => 14000]));

        // House 4 — Ezekiel Nyajong (July paid on the 5th, per sheet)
        $house = $this->house($block, '4', 'other', 15000, 15000);
        $t = $this->moveIn($house, ['name' => 'Ezekiel Nyajong', 'phone' => '0729930465'], '2024-01-01', 15000, 15000);
        $schedule = array_fill_keys(range(1, 9), ['paid' => 15000]);
        $schedule[7] = ['paid' => 15000, 'note' => 'Paid on the 5th, per historical records.'];
        $this->applySchedule($t, $schedule);

        // House 5 — Hamisi Aross Murenga
        $house = $this->house($block, '5', 'other', 14000, 14000);
        $t = $this->moveIn(
            $house,
            ['name' => 'Hamisi Aross Murenga', 'phone' => '0725073135'],
            '2024-01-01',
            14000,
            14000,
            "Unresolved historical annotation carried over verbatim from the spreadsheet: ".
            "\"20k Received, 11k Received, 10k, Move in July/2025, Deposit 1 month, Balance 11k\". ".
            'This may describe a later tenancy change on this house — please review and correct if needed.'
        );
        $this->applySchedule($t, [
            1 => ['paid' => 14000],
            2 => ['paid' => 10000, 'note' => 'Sheet recorded "10k received 23rd" — partial payment.'],
            3 => ['paid' => 14000],
            4 => ['paid' => 14000],
            5 => ['paid' => 14000],
            6 => ['paid' => 14000, 'note' => 'Sheet recorded "20k" received this month; full rent amount applied here.'],
            7 => ['paid' => 14000],
            8 => ['paid' => 14000, 'note' => 'Sheet noted "Deposit 1 month" against this month; meaning unclear from records.'],
            9 => ['paid' => 14000],
        ]);

        // House 6 — Jason Wekesa (pays by 12th)
        $house = $this->house($block, '6', 'other', 14000, 14000);
        $t = $this->moveIn($house, ['name' => 'Jason Wekesa', 'phone' => '0789152589', 'payment_due_day' => 12, 'notes' => 'Pays by the 12th.'], '2024-01-01', 14000, 14000);
        $this->applySchedule($t, array_fill_keys(range(1, 9), ['paid' => 14000]));

        // House 7 — Jackline Kadzo (pays by 10th)
        $house = $this->house($block, '7', 'other', 13500, 13500);
        $t = $this->moveIn($house, ['name' => 'Jackline Kadzo', 'phone' => '0727164838', 'payment_due_day' => 10, 'notes' => 'Pays by the 10th.'], '2024-01-01', 13500, 13500);
        $this->applySchedule($t, array_fill_keys(range(1, 9), ['paid' => 13500]));

        // House 8 — Shem Shikuku
        $house = $this->house($block, '8', 'other', 13000, 13000);
        $t = $this->moveIn($house, ['name' => 'Shem Shikuku', 'phone' => '0704482828', 'notes' => 'Asterisk (*) noted next to name in original records; footnote meaning not specified.'], '2024-01-01', 13000, 13000);
        $this->applySchedule($t, array_fill_keys(range(1, 9), ['paid' => 13000]));

        // House 9 — Januarius Musyoka (Tabitha)
        $house = $this->house($block, '9', 'other', 13000, 13000);
        $t = $this->moveIn($house, ['name' => 'Januarius Musyoka (Tabitha)', 'phone' => '0712442525'], '2024-01-01', 13000, 13000);
        $this->applySchedule($t, array_fill_keys(range(1, 10), ['paid' => 13000]));

        // House 10 — Jane Anyango Sifuna (Claudia Kubati)
        $house = $this->house($block, '10', 'other', 14000, 14000);
        $t = $this->moveIn(
            $house,
            ['name' => 'Jane Anyango Sifuna (Claudia Kubati)', 'phone' => '0714170766'],
            '2024-01-01',
            14000,
            14000,
            'Sheet noted an additional garbled balance figure ("Balance 1500k") alongside the October note below; preserved here for reference.'
        );
        $schedule = array_fill_keys(range(1, 9), ['paid' => 14000]);
        $schedule[10] = ['note' => 'Sheet recorded "Balance 29,000 — experiencing financial hardship" as of October.'];
        $this->applySchedule($t, $schedule);

        // House 11 — Ochieng Kevin
        $house = $this->house($block, '11', 'other', 15000, 15000);
        $t = $this->moveIn($house, ['name' => 'Ochieng Kevin', 'phone' => '0711472659'], '2024-01-01', 15000, 15000);
        $this->applySchedule($t, array_fill_keys(range(1, 9), ['paid' => 15000]));

        // House 12 — Paul Odegu / Nancy Ondiek
        $house = $this->house($block, '12', 'other', 14000, 14000);
        $t = $this->moveIn(
            $house,
            ['name' => 'Paul Odegu / Nancy Ondiek', 'phone' => '0721224617'],
            '2024-01-01',
            14000,
            14000,
            'Unresolved historical annotation carried over verbatim: "Move in Feb/2025, Deposit 14k" — may describe a later tenancy change on this house; please review.'
        );
        $this->applySchedule($t, array_fill_keys(range(1, 9), ['paid' => 14000]));
    }

    private function blockB(Property $property): void
    {
        $block = $this->block($property, 'Block B');

        // Houses 1-4 are marked "N/A" in the records — real slots, no tenant, no rent configured yet.
        foreach (['1', '2', '3', '4'] as $number) {
            $this->house($block, $number, 'other', 0, 0, 'shared', 'shared', 'Rent not yet configured — historical records show this unit as inactive/no tenant (N/A).');
        }

        // House 5 (SQ) — Caretaker Moha
        $house = $this->house($block, '5', 'sq', 5000, 5000);
        $t = $this->moveIn($house, ['name' => 'Caretaker Moha', 'phone' => '0000000005', 'notes' => 'On-site caretaker unit — reduced rent.'], '2024-01-01', 5000, 5000);
        $this->applySchedule($t, array_fill_keys(range(1, 2), ['paid' => 5000]));

        // House 6 (Main) — Sylvia Mweni / Kipkorir
        $house = $this->house($block, '6', 'house', 14500, 14500);
        $t = $this->moveIn(
            $house,
            ['name' => 'Sylvia Mweni / Kipkorir', 'phone' => '0722318360'],
            '2024-01-01',
            14500,
            14500,
            'Unresolved historical annotation carried over verbatim: "Balance 14,500 (June/25)".'
        );
        $this->applySchedule($t, [
            1 => ['paid' => 14500],
            2 => ['paid' => 14500],
            3 => ['paid' => 14500],
            4 => ['paid' => 14500],
            5 => ['paid' => 14500],
            6 => ['paid' => 14500],
            7 => ['paid' => 13000, 'note' => 'Sheet recorded "Balance 1500" — partial payment.'],
            8 => ['paid' => 14500],
            9 => ['paid' => 14500],
            10 => ['paid' => 14500],
        ]);

        // House 7 (SQ) — Isaac Bundi
        $house = $this->house($block, '7', 'sq', 5000, 5000);
        $t = $this->moveIn($house, ['name' => 'Isaac Bundi', 'phone' => '0724386431', 'notes' => 'Phone number listed belongs to tenant\'s mother, per historical records.'], '2024-01-01', 5000, 5000);
        $this->applySchedule($t, array_fill_keys(range(1, 7), ['paid' => 5000]));
    }

    private function blockC(Property $property): void
    {
        $block = $this->block($property, 'Block C');

        // House 1 — Josphat Mbaye
        $house = $this->house($block, '1', 'other', 10000, 10000);
        $t = $this->moveIn(
            $house,
            ['name' => 'Josphat Mbaye', 'phone' => '0726441644'],
            '2024-01-01',
            10000,
            10000,
            'Unresolved historical annotation carried over verbatim: "Move in July/25, Balance 5k".'
        );
        $this->applySchedule($t, [
            1 => ['paid' => 10000],
            2 => ['paid' => 10000],
            3 => ['paid' => 10000],
            4 => ['paid' => 10000],
            5 => ['paid' => 10000],
            6 => ['paid' => 10000],
            7 => ['deposit' => 10000, 'note' => 'Deposit of 10,000 paid this month per records; rent recorded as unpaid.'],
            8 => ['paid' => 10000],
            9 => ['paid' => 5000, 'note' => 'Sheet recorded "5k received" — partial payment.'],
            10 => ['paid' => 10000],
        ]);

        // House 2 — Boytone Omondi (pays 26th)
        $house = $this->house($block, '2', 'other', 10000, 10000);
        $t = $this->moveIn($house, ['name' => 'Boytone Omondi', 'phone' => '0708828020', 'payment_due_day' => 26, 'notes' => 'Pays by the 26th.'], '2024-01-01', 10000, 10000);
        $this->applySchedule($t, array_fill_keys(range(1, 9), ['paid' => 10000]));

        // House 3 — Anne Muringari (records indicate move-in 24/09/25, after several ambiguous deposit notes)
        $house = $this->house($block, '3', 'other', 11000, 11000);
        $t = $this->moveIn(
            $house,
            ['name' => 'Anne Muringari', 'phone' => '0712361252', 'notes' => 'Alternate phone on file: 0737361252.'],
            '2025-09-24',
            11000,
            11000,
            'Historical records show ambiguous earlier notes ("Deposit", "Deposit 10k") alongside 2024 rent ticks that predate the recorded move-in date of 24/09/2025. '.
            'Transcribed as a single tenancy starting on the stated move-in date; please review against the original records.'
        );
        DepositPayment::create([
            'tenancy_id' => $t->id,
            'amount' => 10000,
            'payment_date' => '2025-09-24',
            'method' => 'other',
            'notes' => 'Seeded from historical spreadsheet records ("Deposit 10k").',
        ]);
    }

    private function blockD(Property $property): void
    {
        $block = $this->block($property, 'Block D');

        // House 1 — Noah Mbugua
        $house = $this->house($block, '1', 'other', 10000, 10000);
        $t = $this->moveIn($house, ['name' => 'Noah Mbugua', 'phone' => '0723692848'], '2024-01-01', 10000, 10000);
        $this->applySchedule($t, [
            1 => ['deposit' => 10000, 'no_charge' => true],
            2 => ['paid' => 10000],
        ]);
    }
}

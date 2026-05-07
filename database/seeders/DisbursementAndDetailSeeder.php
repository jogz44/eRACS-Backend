<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DisbursementAndDetailSeeder extends Seeder
{
    // Realistic payees per expense class name (partial match)
    private array $payeePool = [
        'PERSONAL SERVICES'    => ['Barangay Officials', 'Hon. Officials', 'Brgy. Functionaries'],
        'MOOE'                 => ['ABC Supplies Inc.', 'City Hardware', 'Davao Light', 'DCWD', 'Shell Gasoline', 'Mercury Drug'],
        'LOCALLY FUNDED'       => ['PhilHealth Pharmacy', 'Red Cross Davao', 'DSWD Office', 'LGU Tagum City', 'Local Supplier'],
        'CAPITAL OUTLAY'       => ['Tech Solutions PH', 'Office Depot PH', 'Argos Electronics'],
        'BDRRMF'               => ['DSWD Relief Goods', 'Tagum City DRRM', 'Local Hardware'],
        '20% DEVELOPMENT'      => ['City Electric Contractor', 'Tagum Infrastructure', 'Barangay Engineer'],
        'SK'                   => ['SK Officials', 'Youth Center Supplies', 'SK Treasurer'],
    ];

    private array $particularPool = [
        'Purchase of office supplies for Q{q} {year}',
        'Payment for {type} services - {month} {year}',
        'Procurement of materials for {type}',
        '{type} expenses for {month} {year}',
        'Disbursement for {type} - {year}',
        'Payment to {payee} for {type} program',
        'Quarterly release - {type} fund',
        'Operating expenses - {month} {year}',
    ];

    public function run(): void
    {
        $now       = now();
        $bankId    = DB::table('lib_banks')->value('id');

        // Load all appropriations with their related class name for payee selection
        $appropriations = DB::table('tran_appropriations as ta')
            ->join('lib_expense_classes as ec', 'ta.expense_class_id', '=', 'ec.id')
            ->join('lib_fiscal_years as fy', 'ec.fiscal_year_id', '=', 'fy.id')
            ->select('ta.*', 'ec.name as class_name', 'fy.year as fiscal_year')
            ->get();

        // Load users indexed by barangay_id (admin user)
        $usersByBarangay = DB::table('barangay_users')
            ->where('role', 'admin')
            ->get()
            ->keyBy('barangay_id');

        $totalDisbursements = 0;
        $totalDetails       = 0;

        $months = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

        // DV number counter per barangay per year
        $dvCounters = [];

        foreach ($appropriations as $approp) {
            $user = $usersByBarangay->get($approp->barangay_id);
            if (!$user || !$bankId) continue;

            $year         = $approp->fiscal_year;
            $barangayId   = $approp->barangay_id;
            $appropriationAmount = (float) $approp->amount;

            // Determine how many disbursements to spread across the year (2–4)
            // Use deterministic spread based on appropriation id so it's reproducible
            $disbursementCount = ($approp->id % 3) + 2; // 2, 3, or 4

            // Pick spread months evenly
            $spreadMonths = $this->spreadMonths($months, $disbursementCount, $approp->id);

            // Each disbursement gets a portion of the appropriation
            $portions = $this->splitAmount($appropriationAmount, $disbursementCount, $approp->id);

            // Determine payee pool key
            $payeeKey = $this->resolvePayeeKey($approp->class_name);
            $payees   = $this->payeePool[$payeeKey];

            foreach (array_keys($spreadMonths) as $idx) {
                $month = $spreadMonths[$idx];
                $day   = (($approp->id + $idx * 7) % 25) + 1; // 1–25
                $date  = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $dvAmt = $portions[$idx];

                // DV number: DV-BRGY-YEAR-SEQNO
                $dvCounterKey = "{$barangayId}_{$year}";
                $dvCounters[$dvCounterKey] = ($dvCounters[$dvCounterKey] ?? 0) + 1;
                $dvNumber = sprintf('DV-%d-%d-%04d', $barangayId, $year, $dvCounters[$dvCounterKey]);

                $payee      = $payees[$approp->id % count($payees)];
                $particular = $this->buildParticular($approp->class_name, $month, $year, $payee);

                // Insert disbursement
                $disbursementId = DB::table('disbursements')->insertGetId([
                    'barangay_id'       => $barangayId,
                    'date'              => $date,
                    'dv_number'         => $dvNumber,
                    'ref_dv_number'     => null,
                    'cheque_number'     => sprintf('CHK-%04d-%04d', $year, $dvCounters[$dvCounterKey]),
                    'bank_id'           => $bankId,
                    'payee'             => $payee,
                    'dv_amount'         => $dvAmt,
                    'liquidated_amount' => null,
                    'status'            => $this->resolveStatus($month, $year),
                    'user_id'           => $user->id,
                    'liquidated_at'     => null,
                    'remarks'           => null,
                    'rejection_remarks' => null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);

                // Insert expense detail
                DB::table('tran_expense_details')->insert([
                    'disbursement_id'  => $disbursementId,
                    'appropriation_id' => $approp->id,
                    'amount'           => $dvAmt,
                    'particulars'      => $particular,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);

                $totalDisbursements++;
                $totalDetails++;
            }
        }

        $this->command->info("DisbursementAndDetailSeeder done.");
        $this->command->info("  Disbursements : {$totalDisbursements}");
        $this->command->info("  Expense details: {$totalDetails}");
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Pick $count months spread evenly across the year, offset by seed */
    private function spreadMonths(array $months, int $count, int $seed): array
    {
        $step   = (int) floor(12 / $count);
        $offset = $seed % $step;
        $picked = [];

        for ($i = 0; $i < $count; $i++) {
            $idx      = ($offset + $i * $step) % 12;
            $picked[] = $months[$idx];
        }

        sort($picked);
        return $picked;
    }

    /** Split an amount into $count portions, slightly varied */
    private function splitAmount(float $total, int $count, int $seed): array
    {
        if ($count === 1) return [round($total, 2)];

        $base      = round($total / $count, 2);
        $portions  = array_fill(0, $count, $base);
        // Last portion absorbs rounding remainder
        $portions[$count - 1] = round($total - ($base * ($count - 1)), 2);

        // Vary each portion ±5% so the data looks natural
        $variances = [0.95, 1.00, 1.05, 0.98, 1.02];
        for ($i = 0; $i < $count - 1; $i++) {
            $v = $variances[($seed + $i) % count($variances)];
            $portions[$i] = max(100, round($portions[$i] * $v, 2));
        }

        return $portions;
    }

    private function resolvePayeeKey(string $className): string
    {
        if (str_contains($className, 'PERSONAL'))   return 'PERSONAL SERVICES';
        if (str_contains($className, 'LOCALLY'))    return 'LOCALLY FUNDED';
        if (str_contains($className, 'CAPITAL'))    return 'CAPITAL OUTLAY';
        if (str_contains($className, 'BDRRMF') || str_contains($className, 'DISASTER')) return 'BDRRMF';
        if (str_contains($className, '20%'))        return '20% DEVELOPMENT';
        if (str_contains($className, 'KABATAAN') || str_contains($className, 'SK')) return 'SK';
        return 'MOOE';
    }

    private function buildParticular(string $className, int $month, int $year, string $payee): string
    {
        $monthName = date('F', mktime(0, 0, 0, $month, 1));
        $q         = (int) ceil($month / 3);
        $typeShort = $this->classShortName($className);

        $templates = $this->particularPool;
        $tpl       = $templates[($month + strlen($className)) % count($templates)];

        return str_replace(
            ['{q}', '{year}', '{type}', '{month}', '{payee}'],
            [$q,    $year,    $typeShort, $monthName, $payee],
            $tpl
        );
    }

    private function classShortName(string $name): string
    {
        if (str_contains($name, 'PERSONAL'))  return 'Personnel';
        if (str_contains($name, 'MOOE'))      return 'MOOE';
        if (str_contains($name, 'LOCALLY'))   return 'Local Program';
        if (str_contains($name, 'CAPITAL'))   return 'Capital';
        if (str_contains($name, 'DISASTER'))  return 'DRRM';
        if (str_contains($name, '20%'))       return 'Development';
        if (str_contains($name, 'KABATAAN'))  return 'SK';
        return 'General';
    }

    private function resolveStatus(int $month, int $year): string
    {
        $currentYear  = (int) date('Y');
        $currentMonth = (int) date('n');

        // Past months → Liquidated; current month → Unliquidated
        if ($year < $currentYear) return 'Liquidated';
        if ($year === $currentYear && $month < $currentMonth) return 'Liquidated';
        return 'Unliquidated';
    }
}

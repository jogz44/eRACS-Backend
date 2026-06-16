<?php

namespace Database\Seeders;

use App\Models\BarangayUser;
use App\Models\ContAppropriation;
use App\Models\ContApproAccounts;
use App\Models\ContDisbursement;
use App\Models\ContTranExpenseDetail;
use App\Models\TranAppropriation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seeds `cont_appro_accounts` and optionally `cont_tran_expense_detail`.
 *
 * Run order (recommended):
 * 1. Whatever seeds `tran_appropriations` (committed rows per barangay)
 * 2. ContAppropriationSeeder
 * 3. ContApproAccountSeeder  <-- this file
 * 4. ContDisbursementSeeder (if you want synthetic expense lines attached to DVs)
 *
 * Migration column names are camelCase (`contAppropriation_id`, `continuingYear`, etc.).
 * Ensure your `ContApproAccounts` model sets `$fillable` / `$casts` to match.
 */
class ContApproAccountSeeder extends Seeder
{
    public function run(): void
    {
        $linkExpenseLines = filter_var(
            env('CONT_APPRO_ACCOUNT_SEED_EXPENSE_LINES', true),
            FILTER_VALIDATE_BOOL
        );

        foreach (ContAppropriation::query()->orderBy('id')->cursor() as $contAppr) {
            $userId = BarangayUser::query()
                ->where('barangay_id', $contAppr->barangay_id)
                ->value('id');

            if (!$userId) {
                Log::warning('ContApproAccountSeeder: skip cont appropriation — no barangay user', [
                    'cont_appropriation_id' => $contAppr->id,
                    'barangay_id' => $contAppr->barangay_id,
                ]);
                continue;
            }

            $tranAppr = TranAppropriation::query()
                ->where('barangay_id', $contAppr->barangay_id)
                ->where('status', 'committed')
                ->inRandomOrder()
                ->first();

            if (!$tranAppr) {
                Log::warning('ContApproAccountSeeder: skip cont appropriation — no committed tran_appropriation', [
                    'cont_appropriation_id' => $contAppr->id,
                    'barangay_id' => $contAppr->barangay_id,
                ]);
                continue;
            }

            $original = (float) ($contAppr->appropriation_amount ?? $contAppr->unappropriated_amount ?? 50000);
            $original = max($original, 1000);

            // If you already seeded this pair, skip (idempotent-ish).
            $exists = ContApproAccounts::query()
                ->where('contAppropriation_id', $contAppr->id)
                ->where('tranAppropriation_id', $tranAppr->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $continuingYear = (string) (method_exists($contAppr, 'fiscalYear') && $contAppr->relationLoaded('fiscalYear')
                ? optional($contAppr->fiscalYear)->year
                : (DB::table('lib_fiscal_years')->where('id', $contAppr->fiscal_year_id)->value('year') ?? now()->year));
            $continuingYear = substr(preg_replace('/\D/', '', (string) $continuingYear), 0, 4);
            if (strlen($continuingYear) < 4) {
                $continuingYear = (string) now()->year;
            }

            ContApproAccounts::create([
                'contAppropriation_id' => $contAppr->id,
                'tranAppropriation_id' => $tranAppr->id,
                'original_amount' => $original,
                'current_amount' => $original,
                'continuingYear' => $continuingYear,
                'status' => 'active',
                'user_id' => $userId,
            ]);
        }

        if ($linkExpenseLines) {
            $this->seedExpenseLinesForDisbursementsWithoutLines();
        }
    }

    /**
     * Adds `cont_tran_expense_detail` rows for `ContDisbursement` records that have none,
     * and decrements `current_amount` on the chosen continuing account (same as your controller).
     */
    private function seedExpenseLinesForDisbursementsWithoutLines(): void
    {
        $disbursements = ContDisbursement::query()
            ->whereDoesntHave('expenseDetails')
            ->orderBy('id')
            ->get();

        foreach ($disbursements as $disb) {
            $accountIds = ContApproAccounts::query()
                ->whereIn(
                    'contAppropriation_id',
                    ContAppropriation::query()
                        ->where('barangay_id', $disb->barangay_id)
                        ->pluck('id')
                )
                ->where('status', 'active')
                ->pluck('id');

            if ($accountIds->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($disb, $accountIds) {
                $remaining = (float) $disb->dv_amount;
                $lines = random_int(1, min(3, max(1, (int) ceil($remaining / 5000))));

                for ($i = 0; $i < $lines && $remaining > 0.01; $i++) {
                    $account = ContApproAccounts::query()
                        ->whereIn('id', $accountIds)
                        ->where('current_amount', '>', 0)
                        ->inRandomOrder()
                        ->lockForUpdate()
                        ->first();

                    if (!$account) {
                        break;
                    }

                    $cap = min($remaining, (float) $account->current_amount);
                    if ($cap < 0.01) {
                        continue;
                    }

                    if ($i === $lines - 1) {
                        $lineAmount = $cap;
                    } else {
                        $upper = (int) floor(min($cap * 0.7, $cap));
                        $upper = max(100, $upper);
                        $lower = 100;
                        if ($lower > $upper) {
                            $lineAmount = $cap;
                        } else {
                            $lineAmount = (float) random_int($lower, $upper);
                            $lineAmount = round($lineAmount / 100) * 100;
                            $lineAmount = min($lineAmount, $cap);
                        }
                    }

                    if ($lineAmount <= 0) {
                        continue;
                    }

                    ContTranExpenseDetail::create([
                        'cont_disbursement_id' => $disb->id,
                        'cont_appro_account_id' => $account->id,
                        'amount' => $lineAmount,
                        'particulars' => 'Seeded continuing expense line',
                    ]);

                    $account->current_amount = (float) $account->current_amount - $lineAmount;
                    $account->save();

                    $remaining -= $lineAmount;
                }
            });
        }
    }
}

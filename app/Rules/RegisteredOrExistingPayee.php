<?php

namespace App\Rules;

use App\Models\RegisteredPayee;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Disbursements may only be paid to a registered payee.
 *
 * Registered payees are the source of truth, but historically disbursements
 * were allowed to carry free-text payees. Payees that already exist on a
 * disbursement or continuing disbursement are therefore still accepted so
 * legacy records stay editable, while new payee names must be registered first.
 */
class RegisteredOrExistingPayee implements ValidationRule
{
    /**
     * Tables holding payee names that predate the registered payee library.
     */
    protected array $historyTables = ['disbursements', 'cont_disbursement'];

    public function __construct(protected ?int $barangayId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            // Handled by the required/string rules.
            return;
        }

        $barangayId = $this->barangayId ?? $this->resolveBarangayId();

        if (! $barangayId) {
            // Without a barangay the request cannot be scoped; the controller
            // rejects it separately.
            return;
        }

        $payee = trim($value);

        $isRegistered = RegisteredPayee::query()
            ->where('barangay_id', $barangayId)
            ->where('payee_name', $payee)
            ->exists();

        if ($isRegistered) {
            return;
        }

        foreach ($this->historyTables as $tableName) {
            if (! DB::getSchemaBuilder()->hasTable($tableName)) {
                continue;
            }

            $isLegacy = DB::table($tableName)
                ->where('barangay_id', $barangayId)
                ->where('payee', $payee)
                ->exists();

            if ($isLegacy) {
                return;
            }
        }

        $fail('The selected payee is not a registered payee. Register it under the Registered Payees library first.');
    }

    protected function resolveBarangayId(): ?int
    {
        $request = request();

        // Barangay users are always scoped to their own barangay.
        $barangayId = $request->user()?->barangay_id;

        if ($barangayId) {
            return (int) $barangayId;
        }

        // Admins act on behalf of a barangay supplied in the payload.
        if ($request->user('admin') && $request->filled('barangay_id')) {
            return (int) $request->input('barangay_id');
        }

        return null;
    }
}

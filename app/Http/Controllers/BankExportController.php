<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BankExportFile;

class BankExportController extends Controller
{
    /**
     * Generates the TXT file.
     * Can be called internally by other controllers.
     */
    public function generateFile($disbursementId)
    {
        $export = BankExportFile::with([
            'disbursement.bankCheques.bank',
            'barangaySetup.bank',
        ])
        ->where('disbursement_id', $disbursementId)
        ->first();

        if (!$export) {
            throw new \Exception('No export record found.');
        }

        $disbursement = $export->disbursement;
        $setup = $export->barangaySetup;

        if ($disbursement->bankCheques->isEmpty()) {
            throw new \Exception('No cheque found.');
        }

        /*
        |--------------------------------------------------------------------------
        | Build Detail Records
        |--------------------------------------------------------------------------
        */

        $lines = [];
        $totalAmount = 0;
        $totalCheques = 0;

        foreach ($disbursement->bankCheques as $cheque) {

            $accountNumber = str_pad(
                substr($setup->account_number, 0, 10),
                10,
                '0',
                STR_PAD_LEFT
            );

            $checkNumber = str_pad(
                $cheque->cheque_number,
                10,
                '0',
                STR_PAD_LEFT
            );

            $transactionDate = now()->format('mdY');

            $transactionTime = now()->format('His');

            $checkAmount = str_pad(
                (string) round($cheque->amount * 100),
                14,
                '0',
                STR_PAD_LEFT
            );

            $payee = str_pad(
                strtoupper(substr($disbursement->payee, 0, 40)),
                40,
                ' ',
                STR_PAD_RIGHT
            );

            $checkDate = date(
                'mdY',
                strtotime($cheque->cheque_date)
            );

            $lines[] =
                $accountNumber .
                $checkNumber .
                $transactionDate .
                $transactionTime .
                $checkAmount .
                $payee .
                $checkDate .
                '00' .
                '1' .
                '0' .
                '001';

            $totalCheques++;

            $totalAmount += $cheque->amount;
        }

        /*
        |--------------------------------------------------------------------------
        | Trailer Record
        |--------------------------------------------------------------------------
        */

        // TODO: Replace with LandBank hash algorithm
        $hashTotal = str_repeat('0', 20);

        $chequeCount = str_pad(
            $totalCheques,
            6,
            '0',
            STR_PAD_LEFT
        );

        $totalAmountField = str_pad(
            (string) round($totalAmount * 100),
            16,
            '0',
            STR_PAD_LEFT
        );

        $lines[] =
            '9999999999' .
            $hashTotal .
            $chequeCount .
            $totalAmountField;

        /*
        |--------------------------------------------------------------------------
        | Save TXT File
        |--------------------------------------------------------------------------
        */

        $folder = storage_path('app/bank_exports');

        if (!file_exists($folder)) {
            mkdir($folder, 0755, true);
        }

        $filename = $export->filename;

        $path = $folder . DIRECTORY_SEPARATOR . $filename;

        file_put_contents(
            $path,
            implode("\r\n", $lines)
        );

        /*
        |--------------------------------------------------------------------------
        | Update Database
        |--------------------------------------------------------------------------
        */

        $export->update([
            'filepath' => $path,
            'is_exported' => true,
            'exported_at' => now(),
        ]);

        return [
            'filename' => $filename,
            'path' => $path,
        ];
    }

    /**
     * Manual export endpoint.
     */
    public function export(Request $request, $id)
    {
        $user = $request->user();

        $export = BankExportFile::with('barangaySetup')
            ->where('disbursement_id', $id)
            ->first();

        if (!$export) {
            return response()->json([
                'status' => false,
                'message' => 'No export record found for this disbursement.',
            ], 404);
        }

        if ($export->barangaySetup->barangay_id != $user->barangay_id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        $file = $this->generateFile($id);

        return response()->download(
            $file['path'],
            $file['filename']
        );
    }
}

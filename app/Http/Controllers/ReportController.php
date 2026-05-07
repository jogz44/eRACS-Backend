<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\TranAppropriation;

class ReportController extends Controller
{
    public function getRacReport(Request $request)
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
            'expense_class_id' => 'required|integer',
            'barangay_id' => 'nullable|integer|exists:barangays,id',
        ]);

        // Determine barangay scope: explicit param (admin) or authenticated user's barangay
        $barangayId = $data['barangay_id'] ?? $request->user()?->barangay_id;

        // Debug logging for barangay ID
        \Log::info('RAC Report - Barangay ID Debug', [
            'requested_barangay_id' => $data['barangay_id'] ?? 'not provided',
            'user_barangay_id' => $request->user()?->barangay_id ?? 'not authenticated',
            'final_barangay_id' => $barangayId,
            'is_admin_request' => isset($data['barangay_id']),
            'request_params' => $data
        ]);

        $q = TranAppropriation::with(['expenseClass', 'expenseType', 'expenseItem', 'expenseSubItem', 'details','details.disbursement'])
            ->when($barangayId, fn($qq) => $qq->where('barangay_id', $barangayId))
            ->whereHas('details.disbursement', function($query) use ($data) {
                $query->whereDate('date', '>=', $data['from'])
                      ->whereDate('date', '<=', $data['to']);
            });

        $q->where('expense_class_id', $data['expense_class_id']);

        // Debug: Check total TranAppropriation records for this barangay without disbursement filter
        $totalAppropriationsForBarangay = TranAppropriation::where('barangay_id', $barangayId)->count();
        $appropriationsForClass = TranAppropriation::where('barangay_id', $barangayId)
            ->where('expense_class_id', $data['expense_class_id'])
            ->count();
        \Log::info('RAC Report - Total Appropriations Check', [
            'barangay_id' => $barangayId,
            'total_appropriations_for_barangay' => $totalAppropriationsForBarangay,
            'appropriations_for_class' => $appropriationsForClass,
            'expense_class_id' => $data['expense_class_id']
        ]);

        $initialResults = $q->orderBy('transaction_date')->get();

        // Debug logging for initial query results
        \Log::info('RAC Report - Initial Query Results', [
            'barangay_id' => $barangayId,
            'expense_class_id' => $data['expense_class_id'],
            'date_range' => $data['from'] . ' to ' . $data['to'],
            'total_appropriations_found' => $initialResults->count(),
            'appropriation_ids' => $initialResults->pluck('id')->toArray(),
            'sample_appropriation' => $initialResults->first() ? [
                'id' => $initialResults->first()->id,
                'barangay_id' => $initialResults->first()->barangay_id,
                'expense_class_id' => $initialResults->first()->expense_class_id,
                'details_count' => $initialResults->first()->details->count(),
                'sample_detail' => $initialResults->first()->details->first() ? [
                    'id' => $initialResults->first()->details->first()->id,
                    'amount' => $initialResults->first()->details->first()->amount,
                    'disbursement_id' => $initialResults->first()->details->first()->disbursement_id,
                    'disbursement_date' => $initialResults->first()->details->first()->disbursement ? $initialResults->first()->details->first()->disbursement->date : null
                ] : null
            ] : null
        ]);

        $rows = $initialResults
            ->flatMap(function ($o) use ($data) {
                return $o->details->filter(function ($detail) use ($data) {
                    // Only include details where the disbursement date is within the range
                    $disb = $detail->disbursement;
                    if (!$disb || !$disb->date) return false;

                    $disbDate = \Carbon\Carbon::parse($disb->date)->format('Y-m-d');
                    return $disbDate >= $data['from'] && $disbDate <= $data['to'];
                })->map(function ($detail) use ($o) {
                    $disb = $detail->disbursement;
                    return [
                        'accountTitle' => implode(' - ', array_filter([
                            $o->expenseType->name ?? null,
                            $o->expenseItem->name ?? null,
                            $o->expenseSubItem->name ?? null,
                        ])),
                        'appropriation' => (float) $o->amount,
                        'particular' => $detail?->particulars,
                        'dvNumber' => $disb?->dv_number,
                        'date' => $disb?->date,
                        'payee'    => $disb?->payee,
                        'dvAmount' => (float) ($disb?->dv_amount ?? 0), // DV amount for appropriation column
                        'amount'   => (float) ($detail?->amount ?? 0),
                    ];
                });
            })
            ->groupBy('dvNumber') // Group by DV number
            ->map(function ($group) {
                $firstItem = $group->first();
                $particulars = $group->pluck('particular')->filter()->unique()->implode(', ');

                // Debug logging for particulars
                \Log::info('RAC Particulars', [
                    'dvNumber' => $firstItem['dvNumber'],
                    'individual_particulars' => $group->pluck('particular')->toArray(),
                    'concatenated_particulars' => $particulars
                ]);

                // Calculate appropriation based on expense class - sum amounts for this specific class
                $classAppropriation = $group->sum('amount'); // Sum all amounts for this DV within this class

                // Debug logging to understand the data structure
                \Log::info('RAC Class Appropriation', [
                    'dvNumber' => $firstItem['dvNumber'],
                    'classAppropriation' => $classAppropriation,
                    'groupItems' => $group->pluck('accountTitle')->toArray(),
                    'groupAmounts' => $group->pluck('amount')->toArray()
                ]);

                // Create a base row with common fields
                $row = [
                    'particular' => $particulars,
                    'dvNumber' => $firstItem['dvNumber'],
                    'date' => $firstItem['date'],
                    'payee' => $firstItem['payee'],
                    'amount' => $group->sum('amount'), // Sum all amounts for this DV
                    'appropriation' => $classAppropriation, // Use the class-specific total amount
                ];

                // Add each account title as a separate column
                $group->each(function ($item) use (&$row) {
                    $accountTitle = $item['accountTitle'];
                    if ($accountTitle) {
                        // Create a unique key for this account title - preserve dashes, only replace spaces and special chars
                        $key = 'amount_' . strtolower(str_replace([' ', '&', '.', '(', ')'], ['_', '_', '_', '_', '_'], $accountTitle));
                        $row[$key] = $item['amount'];

                        // Debug logging
                        \Log::info('RAC Account Title', [
                            'dvNumber' => $item['dvNumber'],
                            'accountTitle' => $accountTitle,
                            'key' => $key,
                            'amount' => $item['amount']
                        ]);
                    }
                });

                return $row;
            })
            ->values()
            ->sortBy('dvNumber')
            ->values();

        // Debug logging for final processed rows
        \Log::info('RAC Report - Final Processed Rows', [
            'barangay_id' => $barangayId,
            'expense_class_id' => $data['expense_class_id'],
            'total_rows_after_processing' => $rows->count(),
            'dv_numbers' => $rows->pluck('dvNumber')->toArray(),
            'sample_row' => $rows->first() ? array_slice($rows->first(), 0, 5) : null // First 5 keys/values
        ]);

        // Extract account titles and create key map
        $accountTitles = [];
        $accountTitleKeyMap = [];

        $rows->each(function ($row) use (&$accountTitles, &$accountTitleKeyMap) {
            foreach ($row as $key => $value) {
                if (str_starts_with($key, 'amount_')) {
                    // Convert key back to readable account title
                    $accountTitle = str_replace('amount_', '', $key);
                    $accountTitle = str_replace('_', ' ', $accountTitle);
                    $accountTitle = preg_replace('/\s+/', ' ', trim($accountTitle));

                    if (!in_array($accountTitle, $accountTitles)) {
                        $accountTitles[] = $accountTitle;
                        $accountTitleKeyMap[$accountTitle] = $key;
                    }
                }
            }
        });

        $summary = [
            'count' => $rows->count(),
            'total' => round($rows->sum('amount'), 2),
            'range' => ['from' => $data['from'], 'to' => $data['to']],
        ];

        return response()->json([
            'data' => [
                'rows' => $rows,
                'filters' => $data,
                'summary' => $summary,
                'account_titles' => $accountTitles,
                'account_title_key_map' => $accountTitleKeyMap,
            ]
        ]);
    }

    //YEAR FILTER
    public function getAvailableYears(Request $request)
    {
        $barangayId = $request->user()?->barangay_id;

        if (!$barangayId) {
            return response()->json(['years' => []]);
        }

        $years = TranAppropriation::where('barangay_id', $barangayId)
            ->whereNotNull('transaction_date')
            ->selectRaw('YEAR(transaction_date) as year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn($y) => ['label' => (string)$y, 'value' => (int)$y])
            ->values();

        return response()->json(['years' => $years]);
    }

    public function getSacbReport(Request $request)
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
            'barangay_id' => 'nullable|integer|exists:barangays,id',
        ]);

        // Debug logging
        \Log::info('SACB Report Date Range', [
            'from' => $data['from'],
            'to' => $data['to'],
            'barangay_id' => $data['barangay_id'] ?? 'null'
        ]);

        // Determine barangay scope: explicit param (admin) or authenticated user's barangay
        $barangayId = $data['barangay_id'] ?? optional($request->user())->barangay_id;

        $q = TranAppropriation::with(['expenseClass', 'expenseType', 'expenseItem', 'expenseSubItem','details.disbursement'])
            ->when($barangayId, fn($qq) => $qq->where('barangay_id', $barangayId))
            ->where(function ($query) use ($data) {
                $query->whereHas('details.disbursement', function($q2) use ($data) {
                    $q2->whereDate('date', '>=', $data['from'])
                    ->whereDate('date', '<=', $data['to']);
                })
                ->orDoesntHave('details.disbursement'); // include those without disbursements
            });

        $rows = $q->orderBy('transaction_date')->get()->map(function($o) use ($data) {
            // Filter details to only include those within the date range
            $filteredDetails = $o->details->filter(function ($detail) use ($data) {
                $disb = $detail->disbursement;
                if (!$disb || !$disb->date) return false;

                $disbDate = \Carbon\Carbon::parse($disb->date)->format('Y-m-d');
                return $disbDate >= $data['from'] && $disbDate <= $data['to'];
            });

            return [
                'expense_class_id' => $o->expense_class_id,
                'expense_class_name' => $o->expenseClass?->name,
                'expense_class_order' => $o->expenseClass?->order,
                'expense_type_id' => $o->expense_type_id,
                'expense_type_name' => $o->expenseType?->name,
                'expense_item_id' => $o->expense_item_id,
                'expense_item_name' => $o->expenseItem?->name,
                'expense_sub_item_id' => $o->expense_sub_item_id,
                'expense_sub_item_name' => $o->expenseSubItem?->name,
                'appropriation' => (float)$o->amount,
                'obligation' => (float) $filteredDetails->sum('amount'),
                'balance' => (float) $o->amount - (float) $filteredDetails->sum('amount'),
            ];
        });

        // Build hierarchical structure similar to ViewCommitDialog.vue
        $hierarchicalData = [];
        $classMap = [];

        // Group by expense class first
        $rows->each(function ($row) use (&$classMap) {
            $classId = $row['expense_class_id'];
            $className = $row['expense_class_name'];
            $classOrder = $row['expense_class_order'];

            if (!isset($classMap[$classId])) {
                $classMap[$classId] = [
                    'id' => $classId,
                    'name' => $className,
                    'order' => $classOrder,
                    'types' => [],
                    'total_appropriation' => 0,
                    'total_obligation' => 0,
                    'total_balance' => 0,
                ];
            }

            // Add to class totals
            $classMap[$classId]['total_appropriation'] += $row['appropriation'];
            $classMap[$classId]['total_obligation'] += $row['obligation'];
            $classMap[$classId]['total_balance'] += $row['balance'];

            // Handle type level
            $typeId = $row['expense_type_id'];
            $typeName = $row['expense_type_name'];

            if ($typeId && !isset($classMap[$classId]['types'][$typeId])) {
                $classMap[$classId]['types'][$typeId] = [
                    'id' => $typeId,
                    'name' => $typeName,
                    'items' => [],
                    'total_appropriation' => 0,
                    'total_obligation' => 0,
                    'total_balance' => 0,
                ];
            }

            if ($typeId) {
                // Add to type totals
                $classMap[$classId]['types'][$typeId]['total_appropriation'] += $row['appropriation'];
                $classMap[$classId]['types'][$typeId]['total_obligation'] += $row['obligation'];
                $classMap[$classId]['types'][$typeId]['total_balance'] += $row['balance'];

                // Handle item level
                $itemId = $row['expense_item_id'];
                $itemName = $row['expense_item_name'];

                if ($itemId && !isset($classMap[$classId]['types'][$typeId]['items'][$itemId])) {
                    $classMap[$classId]['types'][$typeId]['items'][$itemId] = [
                        'id' => $itemId,
                        'name' => $itemName,
                        'sub_items' => [],
                        'total_appropriation' => 0,
                        'total_obligation' => 0,
                        'total_balance' => 0,
                    ];
                }

                if ($itemId) {
                    // Add to item totals
                    $classMap[$classId]['types'][$typeId]['items'][$itemId]['total_appropriation'] += $row['appropriation'];
                    $classMap[$classId]['types'][$typeId]['items'][$itemId]['total_obligation'] += $row['obligation'];
                    $classMap[$classId]['types'][$typeId]['items'][$itemId]['total_balance'] += $row['balance'];

                    // Handle sub-item level
                    $subItemId = $row['expense_sub_item_id'];
                    $subItemName = $row['expense_sub_item_name'];

                    if ($subItemId) {
                        $classMap[$classId]['types'][$typeId]['items'][$itemId]['sub_items'][$subItemId] = [
                            'id' => $subItemId,
                            'name' => $subItemName,
                            'appropriation' => $row['appropriation'],
                            'obligation' => $row['obligation'],
                            'balance' => $row['balance'],
                        ];
                    }
                }
            }
        });

        // Convert to hierarchical structure for frontend
        $hierarchicalRows = [];
        $classCounter = 1;

        foreach ($classMap as $classId => $class) {
            // Add expense class header with total amounts from all children
            $hierarchicalRows[] = [
                'isSection' => true,
                'ppa' => $classCounter . '. ' . $class['name'],
                'appropriation' => $class['total_appropriation'],  // Always show class totals
                'obligation' => $class['total_obligation'],
                'balance' => $class['total_balance'],
            ];

            // Add expense types
            foreach ($class['types'] as $typeId => $type) {
                // Only show type totals if it has no items
                $hasItems = !empty($type['items']);
                $hierarchicalRows[] = [
                    'isType' => true,
                    'ppa' => $type['name'],
                    'appropriation' => $hasItems ? null : $type['total_appropriation'],
                    'obligation' => $hasItems ? null : $type['total_obligation'],
                    'balance' => $hasItems ? null : $type['total_balance'],
                ];

                // Add expense items
                foreach ($type['items'] as $itemId => $item) {
                    // Only show item totals if it has no sub-items
                    $hasSubItems = !empty($item['sub_items']);
                    $hierarchicalRows[] = [
                        'isItem' => true,
                        'ppa' => $item['name'],
                        'appropriation' => $hasSubItems ? null : $item['total_appropriation'],
                        'obligation' => $hasSubItems ? null : $item['total_obligation'],
                        'balance' => $hasSubItems ? null : $item['total_balance'],
                    ];

                    // Add sub-items if they exist (always show amounts for sub-items as they are leaves)
                    foreach ($item['sub_items'] as $subItemId => $subItem) {
                        $hierarchicalRows[] = [
                            'isSubItem' => true,
                            'ppa' => $subItem['name'],
                            'appropriation' => $subItem['appropriation'],
                            'obligation' => $subItem['obligation'],
                            'balance' => $subItem['balance'],
                        ];
                    }
                }
            }

            $classCounter++;
        }

        $rows = collect($hierarchicalRows);

        $summary = [
            'count' => $rows->count(),
            'total_appropriation' => round($rows->sum('appropriation'), 2),
            'total_obligation' => round($rows->sum('obligation'), 2),
            'total_balance' => round($rows->sum('balance'), 2),
            'range' => ['from' => $data['from'], 'to' => $data['to']],
        ];

        return response()->json([
            'data' => [
                'rows' => $rows,
                'filters' => $data,
                'summary' => $summary,
            ]
        ]);
    }

    public function exportPdf(Request $request)
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
            'class_id' => 'nullable|integer',
            'download' => 'nullable|boolean',
        ]);

        $q = Order::query()->whereBetween('date', [$data['from'], $data['to']]);
        if (!empty($data['class_id'])) $q->where('class_id', $data['class_id']);

        $rows = $q->orderBy('date')->get()->map(fn($o) => [
            'date' => $o->date->toDateString(),
            'student' => $o->student_name,
            'class_id' => $o->class_id,
            'amount' => (float)$o->amount,
        ])->toArray();

        $summary = [
            'count' => count($rows),
            'total' => array_sum(array_column($rows, 'amount')),
            'range' => ['from' => $data['from'], 'to' => $data['to']],
        ];

        $pdf = Pdf::loadView('pdf.sales-report', [
            'summary' => $summary,
            'rows' => $rows,
        ])->setPaper('A4', 'portrait');

        $filename = 'sales-report_'.now()->format('Ymd_His').'.pdf';
        return $request->boolean('download', true) ? $pdf->download($filename) : $pdf->stream($filename);
    }
}

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

        $q = TranAppropriation::with([
            'expenseClass',
            'expenseType',
            'expenseItem',
            'expenseSubItem',
            'expenseSubType',
            'expenseSubSubType',
            'details',
            'details.disbursement',
        ])
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
                            $o->expenseType?->name,
                            $o->expenseItem?->name,
                            $o->expenseSubItem?->name,
                            $o->expenseSubType?->name,
                            $o->expenseSubSubType?->name,
                        ])),
                        'appropriation' => (float) $o->amount,
                        'particular' => $detail?->particulars,
                        'dvNumber' => $disb?->dv_number,
                        'date' => $disb?->date,
                        'payee'    => $disb?->payee,
                        'payee2'    => $disb?->payee2,
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
                    'payee2' => $firstItem['payee2'],
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

        $q = TranAppropriation::with([
            'expenseClass',
            'expenseType',
            'expenseItem',
            'expenseSubItem',
            'expenseSubType',
            'expenseSubSubType',
            'details.disbursement',
        ])
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
                // EXPENSE CLASS
                'expense_class_id' => $o->expense_class_id,
                'expense_class_name' => $o->expenseClass?->name,
                'expense_class_order' => $o->expenseClass?->order,

                // EXPENSE TYPE
                'expense_type_id' => $o->expense_type_id,
                'expense_type_name' => $o->expenseType?->name,
                'expense_type_order' => $o->expenseType?->order,

                // EXPENSE ITEM
                'expense_item_id' => $o->expense_item_id,
                'expense_item_name' => $o->expenseItem?->name,
                'expense_item_order' => $o->expenseItem?->order,

                // EXPENSE SUB ITEM
                'expense_sub_item_id' => $o->expense_sub_item_id,
                'expense_sub_item_name' => $o->expenseSubItem?->name,
                'expense_sub_item_order' => $o->expenseSubItem?->order,

                // EXPENSE SUB TYPE
                'expense_sub_type_id' => $o->expense_sub_type_id,
                'expense_sub_type_name' => $o->expenseSubType?->name,
                'expense_sub_type_order' => $o->expenseSubType?->order,

                // EXPENSE SUB SUB TYPE
                'expense_sub_sub_type_id' => $o->expense_sub_sub_type_id,
                'expense_sub_sub_type_name' => $o->expenseSubSubType?->name,
                'expense_sub_sub_type_order' => $o->expenseSubSubType?->order,

                // AMOUNTS
                'appropriation' => (float) $o->amount,
                'obligation' => (float) $filteredDetails->sum('amount'),
                'balance' => (float) $o->amount - (float) $filteredDetails->sum('amount'),
            ];
        });

        \Log::info('SACB SIX LEVEL DATA', [
            'rows' => $rows->map(function ($row) {
                return [
                    'expense_class_id' => $row['expense_class_id'],
                    'expense_type_id' => $row['expense_type_id'],
                    'expense_item_id' => $row['expense_item_id'],
                    'expense_sub_item_id' => $row['expense_sub_item_id'],
                    'expense_sub_type_id' => $row['expense_sub_type_id'],
                    'expense_sub_sub_type_id' => $row['expense_sub_sub_type_id'],

                    'expense_class_name' => $row['expense_class_name'],
                    'expense_type_name' => $row['expense_type_name'],
                    'expense_item_name' => $row['expense_item_name'],
                    'expense_sub_item_name' => $row['expense_sub_item_name'],
                    'expense_sub_type_name' => $row['expense_sub_type_name'],
                    'expense_sub_sub_type_name' => $row['expense_sub_sub_type_name'],
                ];
            })->values()->toArray(),
        ]);

        // Build six-level hierarchical structure:
        //
        // Expense Class
        //   └── Expense Type
        //       └── Expense Item
        //           └── Expense Sub Item
        //               └── Expense Sub Type
        //                   └── Expense Sub Sub Type

        $classMap = [];

        //BUILD HIERARCHY
        $rows->each(function ($row) use (&$classMap) {

            $classId = $row['expense_class_id'] ?? null;

            if (!$classId) {
                return;
            }

            //EXPENSE CLASS
            if (!isset($classMap[$classId])) {
                $classMap[$classId] = [
                    'id' => $classId,
                    'name' => $row['expense_class_name'],
                    'order' => $row['expense_class_order'] ?? 0,

                    'types' => [],

                    'total_appropriation' => 0,
                    'total_obligation' => 0,
                    'total_balance' => 0,
                ];
            }

            $class =& $classMap[$classId];

            $class['total_appropriation'] += $row['appropriation'];
            $class['total_obligation'] += $row['obligation'];
            $class['total_balance'] += $row['balance'];

            //EXPENSE TYPE
            $typeId = $row['expense_type_id'] ?? null;

            if (!$typeId) {
                unset($class);
                return;
            }

            if (!isset($class['types'][$typeId])) {
                $class['types'][$typeId] = [
                    'id' => $typeId,
                    'name' => $row['expense_type_name'],
                    'order' => $row['expense_type_order'] ?? 0,

                    'items' => [],

                    'total_appropriation' => 0,
                    'total_obligation' => 0,
                    'total_balance' => 0,
                ];
            }

            $type =& $class['types'][$typeId];

            $type['total_appropriation'] += $row['appropriation'];
            $type['total_obligation'] += $row['obligation'];
            $type['total_balance'] += $row['balance'];

            //EXPENSE ITEM
            $itemId = $row['expense_item_id'] ?? null;

            if (!$itemId) {
                unset($type, $class);
                return;
            }

            if (!isset($type['items'][$itemId])) {
                $type['items'][$itemId] = [
                    'id' => $itemId,
                    'name' => $row['expense_item_name'],
                    'order' => $row['expense_item_order'] ?? 0,

                    'sub_items' => [],

                    'total_appropriation' => 0,
                    'total_obligation' => 0,
                    'total_balance' => 0,
                ];
            }

            $item =& $type['items'][$itemId];

            $item['total_appropriation'] += $row['appropriation'];
            $item['total_obligation'] += $row['obligation'];
            $item['total_balance'] += $row['balance'];

            //EXPENSE SUB ITEM
            $subItemId = $row['expense_sub_item_id'] ?? null;

            if (!$subItemId) {
                unset($item, $type, $class);
                return;
            }

            if (!isset($item['sub_items'][$subItemId])) {
                $item['sub_items'][$subItemId] = [
                    'id' => $subItemId,
                    'name' => $row['expense_sub_item_name'],
                    'order' => $row['expense_sub_item_order'] ?? 0,

                    'sub_types' => [],

                    'total_appropriation' => 0,
                    'total_obligation' => 0,
                    'total_balance' => 0,

                    // Leaf totals when this Sub Item has no Sub Type
                    'leaf_appropriation' => 0,
                    'leaf_obligation' => 0,
                    'leaf_balance' => 0,
                ];
            }

            $subItem =& $item['sub_items'][$subItemId];

            $subItem['total_appropriation'] += $row['appropriation'];
            $subItem['total_obligation'] += $row['obligation'];
            $subItem['total_balance'] += $row['balance'];

            //EXPENSE SUB TYPE
            $subTypeId = $row['expense_sub_type_id'] ?? null;

            if (!$subTypeId) {

                // If this sub-item has no lower hierarchy,
                // it becomes the leaf.
                $subItem['leaf_appropriation'] +=
                    $row['appropriation'];

                $subItem['leaf_obligation'] +=
                    $row['obligation'];

                $subItem['leaf_balance'] +=
                    $row['balance'];

                unset($subItem, $item, $type, $class);
                return;
            }

            if (!isset($subItem['sub_types'][$subTypeId])) {
                $subItem['sub_types'][$subTypeId] = [
                    'id' => $subTypeId,
                    'name' => $row['expense_sub_type_name'],
                    'order' => $row['expense_sub_type_order'] ?? 0,

                    'sub_sub_types' => [],

                    'total_appropriation' => 0,
                    'total_obligation' => 0,
                    'total_balance' => 0,

                    // Leaf totals when this Sub Type has no Sub Sub Type
                    'leaf_appropriation' => 0,
                    'leaf_obligation' => 0,
                    'leaf_balance' => 0,
                ];
            }

            $subType =& $subItem['sub_types'][$subTypeId];

            $subType['total_appropriation'] += $row['appropriation'];
            $subType['total_obligation'] += $row['obligation'];
            $subType['total_balance'] += $row['balance'];

            //EXPENSE SUB SUB TYPE
            $subSubTypeId = $row['expense_sub_sub_type_id'] ?? null;

            if (!$subSubTypeId) {

                // Sub-type is the leaf.
                $subType['leaf_appropriation'] +=
                    $row['appropriation'];

                $subType['leaf_obligation'] +=
                    $row['obligation'];

                $subType['leaf_balance'] +=
                    $row['balance'];

                unset($subType, $subItem, $item, $type, $class);
                return;
            }

            if (!isset($subType['sub_sub_types'][$subSubTypeId])) {
                $subType['sub_sub_types'][$subSubTypeId] = [
                    'id' => $subSubTypeId,
                    'name' => $row['expense_sub_sub_type_name'],
                    'order' => $row['expense_sub_sub_type_order'] ?? 0,

                    'appropriation' => 0,
                    'obligation' => 0,
                    'balance' => 0,
                ];
            }

            //SUB SUB TYPE IS THE LEAF
            $subSubType =& $subType['sub_sub_types'][$subSubTypeId];

            $subSubType['appropriation'] +=
                $row['appropriation'];

            $subSubType['obligation'] +=
                $row['obligation'];

            $subSubType['balance'] +=
                $row['balance'];

            unset(
                $subSubType,
                $subType,
                $subItem,
                $item,
                $type,
                $class
            );
        });

        //CONVERT HIERARCHY TO REPORT ROWS
        $hierarchicalRows = [];

        $classCounter = 1;

        foreach ($classMap as $class) {

            //CLASS
            $hierarchicalRows[] = [
                'isSection' => true,
                'ppa' => $classCounter . '. ' . $class['name'],
                'appropriation' => round($class['total_appropriation'], 2),
                'obligation' => round($class['total_obligation'], 2),
                'balance' => round($class['total_balance'], 2),
            ];

            //TYPES
            foreach ($class['types'] as $type) {

                $hasItems = !empty($type['items']);

                $hierarchicalRows[] = [
                    'isType' => true,
                    'ppa' => $type['name'],
                    'appropriation' => $hasItems
                        ? null
                        : round($type['total_appropriation'], 2),
                    'obligation' => $hasItems
                        ? null
                        : round($type['total_obligation'], 2),
                    'balance' => $hasItems
                        ? null
                        : round($type['total_balance'], 2),
                ];

                //ITEMS
                foreach ($type['items'] as $item) {

                    $hasSubItems = !empty($item['sub_items']);

                    $hierarchicalRows[] = [
                        'isItem' => true,
                        'ppa' => $item['name'],
                        'appropriation' => $hasSubItems
                            ? null
                            : round($item['total_appropriation'], 2),
                        'obligation' => $hasSubItems
                            ? null
                            : round($item['total_obligation'], 2),
                        'balance' => $hasSubItems
                            ? null
                            : round($item['total_balance'], 2),
                    ];

                    //SUB ITEMS
                    foreach ($item['sub_items'] as $subItem) {

                        $hasSubTypes = !empty($subItem['sub_types']);

                        $hierarchicalRows[] = [
                            'isSubItem' => true,
                            'ppa' => $subItem['name'],

                            'appropriation' => $hasSubTypes
                                ? null
                                : round(
                                    $subItem['leaf_appropriation'] ?? 0,
                                    2
                                ),

                            'obligation' => $hasSubTypes
                                ? null
                                : round(
                                    $subItem['leaf_obligation'] ?? 0,
                                    2
                                ),

                            'balance' => $hasSubTypes
                                ? null
                                : round(
                                    $subItem['leaf_balance'] ?? 0,
                                    2
                                ),
                        ];

                        //SUB TYPES
                        foreach ($subItem['sub_types'] as $subType) {

                            $hasSubSubTypes =
                                !empty($subType['sub_sub_types']);

                            $hierarchicalRows[] = [
                                'isSubType' => true,
                                'ppa' => $subType['name'],

                                'appropriation' => $hasSubSubTypes
                                    ? null
                                    : round($subType['leaf_appropriation'] ?? 0, 2),

                                'obligation' => $hasSubSubTypes
                                    ? null
                                    : round($subType['leaf_obligation'] ?? 0, 2),

                                'balance' => $hasSubSubTypes
                                    ? null
                                    : round($subType['leaf_balance'] ?? 0, 2),
                            ];

                            //SUB SUB TYPES
                            foreach (
                                $subType['sub_sub_types']
                                as $subSubType
                            ) {

                                $hierarchicalRows[] = [
                                    'isSubSubType' => true,
                                    'ppa' => $subSubType['name'],

                                    'appropriation' =>
                                        round(
                                            $subSubType['appropriation'],
                                            2
                                        ),

                                    'obligation' =>
                                        round(
                                            $subSubType['obligation'],
                                            2
                                        ),

                                    'balance' =>
                                        round(
                                            $subSubType['balance'],
                                            2
                                        ),
                                ];
                            }
                        }
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

<?php 

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Barangay;
use App\Models\LibFiscalYear;
use App\Models\LibExpenseClass;
use App\Models\LibExpenseType;
use App\Models\LibExpenseItem;

class LibExpenseClassAndTypeSeeder extends Seeder
{

    public function run()
    {
        $barangays = Barangay::all();
        $now = now();

        if ($barangays->isEmpty()) {
            return;
        }

        $years = [2023, 2024, 2025, 2026]; // pre-generate fiscal years

        $classes = [
            ['name' => 'SANGUNIANG KABATAAN (SK) - 10%', 'order' => 0],
            ['name' => 'PERSONAL SERVICES', 'order' => 1],
            ['name' => 'MOOE', 'order' => 2],
            ['name' => 'LOCALLY FUNDED PROJECTS', 'order' => 3],
            ['name' => 'CAPITAL OUTLAY', 'order' => 4],
            ['name' => 'BRGY. DISASTER RISK REDUCTION AND MANAGEMENT FUND (BDRRMF) - 5%', 'order' => 5],
            ['name' => '20% DEVELOPMENT FUND', 'order' => 6],
        ];

        $typesMap = [
            'SANGUNIANG KABATAAN (SK) - 10%' => [
                ['name' => 'MOOE', 'order' => 0],
                ['name' => 'LOCALLY FUNDED PROGRAM', 'order' => 1],
                ['name' => 'CAPITAL OUTLAY', 'order' => 2],
            ],
            'PERSONAL SERVICES' => [
                ['name' => 'Honorarium', 'order' => 0],
                ['name' => 'Cash Gift', 'order' => 1],
                ['name' => 'Leave Credit Benefits', 'order' => 2],
                ['name' => 'Year-End Bonus', 'order' => 3],
                ['name' => 'MID-YEAR BONUS', 'order' => 4],
                ['name' => 'Productivity Enhancement Incentive (PEI)', 'order' => 5],
            ],
            'MOOE' => [
                ['name' => 'Travelling Expenses', 'order' => 0],
                ['name' => 'Training Expense', 'order' => 1],
                ['name' => 'Office Supplies', 'order' => 2],
                ['name' => 'Utitlity Expenses', 'order' => 3],
                ['name' => 'Membership Dues & Contribution to Organization', 'order' => 4],
                ['name' => 'Repair & Maintenance - Vehicles', 'order' => 5],
                ['name' => 'Fuel & Lubricants', 'order' => 6],
                ['name' => 'Repair & Maintenance of Government Facilities', 'order' => 7],
                ['name' => 'Financial Assistance for Brgy. Functionaries', 'order' => 8],
                ['name' => 'Fidelity Bond', 'order' => 9],
                ['name' => 'Subscription Expense', 'order' => 10],
                ['name' => 'Repair of Office Equipment', 'order' => 11],
                ['name' => 'Rent Expense', 'order' => 12],
                ['name' => 'Cable, satellite, telegraph & radio expense', 'order' => 13],
                ['name' => 'Extraordinary Expense', 'order' => 14],
                ['name' => 'Repair & maint. of other Public Infrastructure', 'order' => 15],
                ['name' => 'Accountable Forms Expense', 'order' => 16],
                ['name' => 'Auditing Services', 'order' => 17],
                ['name' => 'Insurance Premium', 'order' => 18],
                ['name' => 'Other MOE', 'order' => 19],
            ],
            'LOCALLY FUNDED PROJECTS' => [
                ['name' => 'Maint. of Peace & Order', 'order' => 0],
                ['name' => 'Environmental Sanitary Program', 'order' => 1],
                ['name' => 'Senior Citizen', 'order' => 2],
                ['name' => 'Health Program', 'order' => 3],
                ['name' => 'Nutrition Program', 'order' => 4],
                ['name' => 'Anti-Rabies Program', 'order' => 5],
                ['name' => 'Lupong Tagapamayapa Program', 'order' => 6],
                ['name' => 'Purok Affairs Program', 'order' => 7],
                ['name' => 'Welfare for Disabled Person', 'order' => 8],
                ['name' => 'HIV/AIDS Awareness', 'order' => 9],
                ['name' => 'Daycare Program', 'order' => 10],
                ['name' => 'Bloodletting Program', 'order' => 11],
                ['name' => 'Livelihood Program (GAD)', 'order' => 12],
                ['name' => 'Electrification Maintenance Program (GAD)', 'order' => 13],
                ['name' => 'VAW Program and Human Rights Program (GAD)', 'order' => 14],
                ['name' => 'Job Fair Program (GAD)', 'order' => 15],
                ['name' => 'Gender and Development Program (GAD)', 'order' => 16],
            ],
            'CAPITAL OUTLAY' => [
                ['name' => 'Bundy Clock', 'order' => 0],
                ['name' => 'IT Equipments', 'order' => 1],
            ],
            'BRGY. DISASTER RISK REDUCTION AND MANAGEMENT FUND (BDRRMF) - 5%' => [
                ['name' => 'Pre & Post Disaster Fund', 'order' => 0],
                ['name' => 'Quick Reponse Fund (QRF)', 'order' => 1],
            ],
            '20% DEVELOPMENT FUND' => [
                ['name' => 'Maintenance of streetlights', 'order' => 0],
                ['name' => 'Construction of Drainage (Prk. 1 & 3A)', 'order' => 1],
                ['name' => 'Construction of Solar Dryer', 'order' => 2],
                ['name' => 'Fabrication of Steel Gate', 'order' => 3],
                ['name' => 'Roof Painting of Multi-Purpose Bldg.', 'order' => 4],
                ['name' => 'Construction of Nursery', 'order' => 5],
                ['name' => 'Maintenance of Roads', 'order' => 6],
            ],
        ];

        $itemsMap = [
            'SANGUNIANG KABATAAN (SK) - 10%' => [
                'MOOE' => [
                    ['name' => 'Training & Seminars', 'order' => 0],
                    ['name' => 'Traveling Expenses', 'order' => 1],
                    ['name' => 'Office Supplies', 'order' => 2],
                    ['name' => 'Other MOOE', 'order' => 3],
                    ['name' => 'Other Supplies', 'order' => 4],
                    ['name' => 'Subsidy to Comelec', 'order' => 5],
                    ['name' => 'Water Expense', 'order' => 6],
                    ['name' => 'Electricity Expense', 'order' => 7],
                    ['name' => 'Repair and Maintenance of Government Vehicle', 'order' => 8],
                    ['name' => 'Repair and Maintenance of Government Facilities', 'order' => 9],
                ],
                'LOCALLY FUNDED PROGRAM' => [
                    ['name' => 'Nutrition Program', 'order' => 0],
                    ['name' => 'Childrens Congress', 'order' => 1],
                    ['name' => 'Araw ng Barangay Activities', 'order' => 2],
                    ['name' => 'Scholarship Program', 'order' => 3],
                    ['name' => 'Poverty Reduction Project', 'order' => 4],
                    ['name' => 'Cultural Assistance', 'order' => 5],
                    ['name' => 'Cultural Program', 'order' => 6],
                    ['name' => 'Sports Festival', 'order' => 7],
                    ['name' => 'Protection of Children R.A 9344', 'order' => 8],
                    ['name' => 'Health Program', 'order' => 9],
                ],
                'CAPITAL OUTLAY' => [
                    ['name' => 'IT Equipment', 'order' => 0],
                ],
            ],
            'MOOE' => [
                'Utility Expenses' => [
                    ['name' => 'Water Expenses', 'order' => 0],
                    ['name' => 'Electricity Expenses', 'order' => 1],
                ],
            ],
            'LOCALLY FUNDED PROJECTS' => [
                'Maint. of Peace & Order' => [
                    ['name' => 'Other MOE', 'order' => 0],
                ],
                'Environmental Sanitary Program' => [
                    ['name' => 'OTHER MOE', 'order' => 0],
                    ['name' => 'Office Supplies', 'order' => 1],
                ],
                'Health Program' => [
                    ['name' => 'Office Supplies', 'order' => 0],
                    ['name' => 'Medicines', 'order' => 1],
                    ['name' => 'Other MOE', 'order' => 2],
                ],
                'Nutrition Program' => [
                    ['name' => 'Other MOE', 'order' => 0],
                    ['name' => 'Office Supplies', 'order' => 1],
                    ['name' => 'Training Expense', 'order' => 2],
                    ['name' => 'Other Supplies', 'order' => 3],
                ],
                'Anti-Rabies Program' => [
                    ['name' => 'Other MOE', 'order' => 0],
                ],
                'Lupong Tagapamayapa Program' => [
                    ['name' => 'Other MOE', 'order' => 0],
                ],
                'Purok Affairs Program' => [
                    ['name' => 'Other MOE', 'order' => 0],
                    ['name' => 'Training Expense', 'order' => 1],
                ],
                'Welfare for Disabled Person' => [
                    ['name' => 'Other MOE', 'order' => 0],
                ],
                'HIV/AIDS Awareness' => [
                    ['name' => 'Other MOE', 'order' => 0],
                ],
                'Daycare Program' => [
                    ['name' => 'Office Supplies', 'order' => 0],
                    ['name' => 'Other MOE', 'order' => 1],
                    ['name' => 'Training Expense', 'order' => 2],
                    ['name' => 'Other Supplies', 'order' => 3],
                ],
                'Bloodletting Program' => [
                    ['name' => 'Other MOE', 'order' => 0],
                ],
                'Livelihood Program (GAD)' => [
                    ['name' => 'Training Expense(GAD)', 'order' => 0],
                ],
                'Electrification Maintenance Program (GAD)' => [
                    ['name' => 'Other Supplies Expense', 'order' => 0],
                ],
                'VAW Program and Human Rights Program (GAD)' => [
                    ['name' => 'Other MOE', 'order' => 0],
                ],
                'Job Fair Program (GAD)' => [
                    ['name' => 'Other MOE', 'order' => 0],
                ],
                'Gender and Development Program (GAD)' => [
                    ['name' => 'Training Expense', 'order' => 0],
                ],
            ],
            'BRGY. DISASTER RISK REDUCTION AND MANAGEMENT FUND (BDRRMF) - 5%' => [
                'Pre & Post Disaster Fund' => [
                    ['name' => 'MOOE', 'order' => 0], //has items
                    ['name' => 'CAPITAL OUTLAY', 'order' => 1], //has items
                ],
            ],
        ];

        $subItemsMap = [
            'BRGY. DISASTER RISK REDUCTION AND MANAGEMENT FUND (BDRRMF) - 5%' => [
                'Pre & Post Disaster Fund' => [
                    'MOOE' => [
                        ['name' => 'Desilting of Drainage Canal', 'order' => 0],
                        ['name' => 'Food Supplies (Relief Goods)', 'order' => 1],
                        ['name' => 'Other Supplies', 'order' => 2],
                        ['name' => 'Training & Seminar', 'order' => 3],
                    ],
                    'CAPITAL OUTLAY' => [
                        ['name' => 'Const. of Drainage', 'order' => 0],
                        ['name' => 'Generator Set', 'order' => 1],
                    ],
                ],
            ],
        ];

        foreach ($barangays as $barangay) {
            foreach ($years as $year) {
                // Ensure fiscal years exist
                $fiscalYear = LibFiscalYear::firstOrCreate(
                    [
                        'barangay_id' => $barangay->id,
                        'year'        => $year,
                    ],
                    [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );

                foreach ($classes as $class) {
                    $classModel = LibExpenseClass::firstOrCreate(
                        [
                            'barangay_id'    => $barangay->id,
                            'fiscal_year_id' => $fiscalYear->id,
                            'name'           => $class['name'],
                        ],
                        [
                            'order'      => $class['order'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );

                    $types = $typesMap[$class['name']] ?? [];
                    foreach ($types as $type) {
                        $typeModel = LibExpenseType::firstOrCreate(
                            [
                                'expense_class_id' => $classModel->id,
                                'name'             => $type['name'],
                            ],
                            [
                                'order'      => $type['order'],
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]
                        );

                        // Seed items via itemsMap if defined for this Class > Type
                        $itemDefs = $itemsMap[$class['name']][$type['name']] ?? [];
                        foreach ($itemDefs as $item) {
                            $itemModel = LibExpenseItem::firstOrCreate(
                                [
                                    'expense_type_id' => $typeModel->id,
                                    'name'            => $item['name'],
                                    'parent_item_id'  => null,
                                ],
                                [
                                    'order'      => $item['order'] ?? 0,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ]
                            );
                            
                            $subItemDefs = $subItemsMap[$class['name']][$type['name']][$item['name']] ?? [];
                            foreach ($subItemDefs as $subItem) {
                                LibExpenseItem::firstOrCreate(
                                    [
                                        'expense_type_id' => $typeModel->id,
                                        'name'            => $subItem['name'],
                                        'parent_item_id'  => $itemModel->id,
                                    ],
                                    [
                                        'order'      => $subItem['order'] ?? 0,
                                        'created_at' => $now,
                                        'updated_at' => $now,
                                    ]
                                );
                            }
                        }
                    }
                }
            }
        }
    }
}

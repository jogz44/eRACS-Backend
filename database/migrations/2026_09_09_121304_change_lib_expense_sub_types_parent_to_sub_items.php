<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | STEP 1
        | Remove the OLD foreign key.
        |
        | OLD:
        | lib_expense_sub_types.sub_item_id
        |     -> lib_expense_items.id
        |--------------------------------------------------------------------------
        */

        Schema::table('lib_expense_sub_types', function (Blueprint $table) {
            $table->dropForeign([
                'sub_item_id'
            ]);
        });


        /*
        |--------------------------------------------------------------------------
        | STEP 2
        | Convert existing legacy Sub-Items
        |
        | OLD:
        |
        | lib_expense_items
        |     53 = parent/root item
        |     54 = Const. of Drainage
        |
        | lib_expense_sub_types
        |     sub_item_id = 54
        |
        | NEW:
        |
        | lib_expense_items
        |     53
        |         ↓
        | lib_expense_sub_items
        |     expense_item_id = 53
        |     name = Const. of Drainage
        |--------------------------------------------------------------------------
        */

        $subTypes = DB::table('lib_expense_sub_types')
            ->get();

        foreach ($subTypes as $subType) {

            /*
             * The existing sub_item_id still contains
             * the OLD lib_expense_items.id.
             */
            $oldItem = DB::table('lib_expense_items')
                ->where('id', $subType->sub_item_id)
                ->first();

            if (!$oldItem) {
                throw new RuntimeException(
                    'Unable to migrate Sub-Type ID '
                    . $subType->id
                    . ': legacy lib_expense_items ID '
                    . $subType->sub_item_id
                    . ' was not found.'
                );
            }


            /*
             * The old child item has:
             *
             * id = 54
             * parent_item_id = 53
             *
             * Therefore the new Sub-Item belongs
             * directly to expense item 53.
             */
            $expenseItemId = $oldItem->parent_item_id;

            if (!$expenseItemId) {
                throw new RuntimeException(
                    'Unable to migrate Sub-Type ID '
                    . $subType->id
                    . ': legacy item ID '
                    . $oldItem->id
                    . ' has no parent_item_id.'
                );
            }


            /*
             * Check whether the new Sub-Item already exists.
             */
            $existingSubItem = DB::table('lib_expense_sub_items')
                ->where('expense_item_id', $expenseItemId)
                ->where('name', $oldItem->name)
                ->first();


            if ($existingSubItem) {

                $newSubItemId = $existingSubItem->id;

            } else {

                /*
                 * Create the new Sub-Item.
                 */
                $newSubItemId = DB::table('lib_expense_sub_items')
                    ->insertGetId([
                        'expense_item_id' => $expenseItemId,
                        'name'            => $oldItem->name,
                        'order'           => $oldItem->order ?? 0,
                        'created_at'      => $oldItem->created_at,
                        'updated_at'      => $oldItem->updated_at,
                    ]);
            }


            /*
             * Change the Sub-Type to point to the NEW
             * lib_expense_sub_items record.
             */
            DB::table('lib_expense_sub_types')
                ->where('id', $subType->id)
                ->update([
                    'sub_item_id' => $newSubItemId,
                ]);
        }


        /*
        |--------------------------------------------------------------------------
        | STEP 3
        | Add the NEW foreign key.
        |
        | NEW:
        |
        | lib_expense_sub_types.sub_item_id
        |     ↓
        | lib_expense_sub_items.id
        |--------------------------------------------------------------------------
        */

        Schema::table('lib_expense_sub_types', function (Blueprint $table) {
            $table->foreign('sub_item_id')
                ->references('id')
                ->on('lib_expense_sub_items')
                ->cascadeOnDelete();
        });
    }


    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Remove NEW foreign key
        |--------------------------------------------------------------------------
        */

        Schema::table('lib_expense_sub_types', function (Blueprint $table) {
            $table->dropForeign([
                'sub_item_id'
            ]);
        });


        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |
        | We don't automatically restore the old child records here.
        | This avoids accidentally deleting or duplicating data.
        |--------------------------------------------------------------------------
        */
    }
};

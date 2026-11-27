<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Zoho items without a category used to be filed under "Zoho POS"; they now belong in "Uncategorised". */
    public function up(): void
    {
        $zohoPos = DB::table('categories')->where('category_name', 'Zoho POS')->first();

        if (! $zohoPos) {
            return;
        }

        $uncategorised = DB::table('categories')
            ->whereRaw('LOWER(category_name) = ?', ['uncategorised'])
            ->first();

        if (! $uncategorised) {
            DB::table('categories')->where('id', $zohoPos->id)->update([
                'category_name' => 'Uncategorised',
                'deleted_at' => null,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('products')->where('category_id', $zohoPos->id)->update(['category_id' => $uncategorised->id]);
        DB::table('categories')->where('id', $uncategorised->id)->update(['deleted_at' => null]);
        DB::table('categories')->where('id', $zohoPos->id)->update(['deleted_at' => now()]);
    }

    public function down(): void
    {
        // Products moved into an existing "Uncategorised" category cannot be told apart afterwards.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * iquesters/product was renamed to iquesters/commerce. An app that had the
 * product module keeps its data: the module row (and so its roles, metas and
 * navigation), its settings and its navigation keys move to "commerce".
 * The tables (products, product_discounts, ...) keep their names.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('product', 'commerce');
    }

    public function down(): void
    {
        $this->rename('commerce', 'product');
    }

    private function rename(string $from, string $to): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $module = DB::table('modules')->where('name', $from)->first();
        if (! $module || DB::table('modules')->where('name', $to)->exists()) {
            return;
        }

        DB::transaction(function () use ($module, $from, $to) {
            DB::table('modules')->where('id', $module->id)->update([
                'name' => $to,
                'description' => str_replace($from.' module', $to.' module', (string) $module->description),
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('module_settings')) {
                DB::table('module_settings')->where('module', 'iquesters/'.$from)->update(['module' => 'iquesters/'.$to]);
            }

            if (Schema::hasTable('navigation_items')) {
                $items = DB::table('navigation_items')->where('ref_module', $module->id)->where('key', 'like', $from.'.%')->get(['id', 'key', 'params']);
                foreach ($items as $item) {
                    $params = $item->params ? json_decode($item->params, true) : null;
                    if (is_array($params) && ($params['module'] ?? null) === $from) {
                        $params['module'] = $to;
                    }
                    DB::table('navigation_items')->where('id', $item->id)->update([
                        'key' => $to.substr($item->key, strlen($from)),
                        'params' => $params === null ? $item->params : json_encode($params),
                    ]);
                }
            }
        });
    }
};

<?php

use App\Http\Middleware\RecordStaffAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Store History: give existing shop log lines a type and category, from the action text they were saved with. */
return new class extends Migration
{
    public function up(): void
    {
        $prefixes = [];
        foreach (RecordStaffAction::LABELS as $route => $label) {
            $prefixes[] = [trim(explode(':ref', $label)[0]), $route];
        }
        usort($prefixes, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0])); // longest match first
        DB::table('audit_logs')->whereNotNull('supplier_id')->whereNull('category')->orderBy('id')->chunkById(500, function ($rows) use ($prefixes) {
            foreach ($rows as $row) {
                $route = null;
                foreach ($prefixes as [$p, $r]) {
                    if ($p !== '' && str_starts_with($row->action, $p)) {
                        $route = $r;
                        break;
                    }
                }
                DB::table('audit_logs')->where('id', $row->id)->update([
                    'type' => $route ? RecordStaffAction::typeOf($route) : 'updated',
                    'category' => $route ? RecordStaffAction::categoryOf($route) : 'settings',
                    'role_label' => $row->role_label ?? ($row->actor_id ? ucfirst((string) $row->role) : 'PartyRent (automatic)'),
                ]);
            }
        });
    }

    public function down(): void
    {
        // keep the labels
    }
};

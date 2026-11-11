<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Earlier versions stored pickup/return times as the agency's wall clock with
 * no zone attached. Users now work from their own zone, so every timestamp
 * must be an absolute UTC instant: reinterpret each legacy value in the
 * agency's configured zone and convert it.
 *
 * UTC agencies are unchanged. The down() reverses the conversion for rollback.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $columns = [
        'scheduled_pickup_at',
        'return_pickup_at',
        'actual_pickup_at',
        'actual_dropoff_at',
    ];

    public function up(): void
    {
        $this->rewrite(fn (string $value, string $zone): string => Carbon::parse($value, $zone)->utc()->toDateTimeString());
    }

    public function down(): void
    {
        $this->rewrite(fn (string $value, string $zone): string => Carbon::parse($value, 'UTC')->setTimezone($zone)->toDateTimeString());
    }

    /**
     * @param  callable(string, string): string  $convert  (stored value, zone) -> new value
     */
    private function rewrite(callable $convert): void
    {
        $tenants = DB::table('tenants')->select('id')->get();

        foreach ($tenants as $tenant) {
            $zone = DB::table('tenant_settings')
                ->where('tenant_id', $tenant->id)
                ->value('timezone');

            if (! is_string($zone) || ! in_array($zone, timezone_identifiers_list(), true)) {
                $zone = 'UTC';
            }

            if ($zone === 'UTC') {
                continue;
            }

            DB::table('rides')
                ->where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($convert, $zone): void {
                    foreach ($rows as $row) {
                        $updates = [];

                        foreach ($this->columns as $column) {
                            $value = $row->{$column} ?? null;

                            if (is_string($value) && $value !== '') {
                                $updates[$column] = $convert($value, $zone);
                            }
                        }

                        if ($updates !== []) {
                            DB::table('rides')->where('id', $row->id)->update($updates);
                        }
                    }
                });
        }
    }
};

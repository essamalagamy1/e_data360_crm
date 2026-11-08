<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fills the new exact-time columns from the existing date + time values, using
 * each company's own timezone. Existing columns are only read, never written.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companyOfEmployee = DB::table('employees')->pluck('created_by', 'id');
        $zones = DB::table('settings')->where('name', 'timezone')->pluck('value', 'created_by');

        DB::table('attendance_employees')
            ->whereNull('clock_in_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($companyOfEmployee, $zones) {
                foreach ($rows as $row) {
                    if (empty($row->clock_in) || $row->clock_in === '00:00:00') {
                        continue;
                    }

                    $zone = $zones[$companyOfEmployee[$row->employee_id] ?? 0] ?? 'Africa/Cairo';

                    try {
                        $in = Carbon::parse($row->date . ' ' . $row->clock_in, $zone);
                        $update = [
                            'clock_in_at' => $in->copy()->utc()->format('Y-m-d H:i:s'),
                        ];

                        if (!empty($row->clock_out) && $row->clock_out !== '00:00:00') {
                            $out = Carbon::parse($row->date . ' ' . $row->clock_out, $zone);
                            if ($out->lessThan($in)) {
                                // Shift crossed midnight.
                                $out->addDay();
                            }
                            $update['clock_out_at'] = $out->copy()->utc()->format('Y-m-d H:i:s');
                            $update['worked_seconds'] = $in->diffInSeconds($out, true);
                        }

                        DB::table('attendance_employees')->where('id', $row->id)->update($update);
                    } catch (\Throwable $e) {
                        // A malformed old row stays as it is and simply shows its stored times.
                        continue;
                    }
                }
            });
    }

    public function down(): void
    {
    }
};

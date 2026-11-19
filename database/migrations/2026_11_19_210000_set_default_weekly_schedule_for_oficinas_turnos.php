<?php

use App\Models\Shift;
use App\Models\Timetable;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $shift = Shift::where('name', 'Oficinas turnos')
            ->orWhere('biotime_pk', 8)
            ->first();

        if (! $shift || is_array($shift->weekly_schedule)) {
            return;
        }

        $t1 = Timetable::where('name', 'Turno oficina 1')->first();
        $t2 = Timetable::where('name', 'Turno oficina 2')->first();

        if ($t1 && $t2) {
            $shift->update([
                'work_weekend' => false,
                'weekly_schedule' => [
                    'sun' => null,
                    'mon' => $t1->id,
                    'tue' => $t1->id,
                    'wed' => $t1->id,
                    'thu' => $t1->id,
                    'fri' => $t2->id,
                    'sat' => null,
                ],
            ]);
        }
    }

    public function down(): void
    {
        // No-op: no borrar configuraciones manuales del usuario
    }
};

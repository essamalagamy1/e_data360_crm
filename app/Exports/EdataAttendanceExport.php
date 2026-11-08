<?php

namespace App\Exports;

use App\Support\Edata\Labels;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;

class EdataAttendanceExport implements FromCollection, WithHeadings, ShouldAutoSize, WithEvents
{
    public function __construct(private Collection $days)
    {
    }

    public function collection(): Collection
    {
        return $this->days->map(function ($day) {
            $flags = collect($day['flags'])
                ->reject(fn ($f) => $f === $day['status'])
                ->map(fn ($f) => Labels::attendance($f)[0])
                ->implode('، ');

            return [
                $day['date'],
                $day['employee_name'],
                $day['in'] ?? '',
                $day['out'] ?? '',
                round($day['seconds'] / 3600, 2),
                Labels::attendance($day['status'])[0],
                $day['late_minutes'],
                round($day['overtime_seconds'] / 3600, 2),
                $flags,
            ];
        });
    }

    public function headings(): array
    {
        return ['التاريخ', 'الموظف', 'وقت الدخول', 'وقت الخروج', 'ساعات العمل', 'الحالة', 'دقائق التأخير', 'ساعات إضافية', 'ملاحظات'];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $event->sheet->getDelegate()->setRightToLeft(true);
                $event->sheet->getDelegate()->getStyle('A1:I1')->getFont()->setBold(true);
            },
        ];
    }
}

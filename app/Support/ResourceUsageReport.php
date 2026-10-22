<?php

namespace App\Support;

use App\Models\Reservation;
use App\Models\ResourceUsage;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ResourceUsageReport
{
    public static function columns(bool $systemWide): array
    {
        return ['usage' => 'Usage Record ID', 'reservation' => 'Reservation ID', 'resident' => 'Resident Name']
            + ($systemWide ? ['barangay' => 'Barangay'] : [])
            + ['resource' => 'Resource Name', 'type' => 'Resource Type', 'approved' => 'Reserved Quantity', 'released' => 'Released Quantity',
                'returned' => 'Returned Quantity', 'outstanding' => 'Outstanding Quantity', 'scheduled_start' => 'Scheduled Start',
                'scheduled_end' => 'Scheduled End', 'actual_start' => 'Actual Start', 'actual_end' => 'Actual End', 'status' => 'Usage Status', 'overdue' => 'Overdue'];
    }

    public static function row(Reservation $reservation, bool $systemWide): array
    {
        $usage = $reservation->resourceUsage;
        $usage?->setRelation('reservation', $reservation);
        $equipment = $reservation->usage_type === 'Equipment';

        return ['usage' => $usage?->id ?? '-', 'reservation' => $reservation->id, 'resident' => $reservation->user?->name ?? 'Former resident']
            + ($systemWide ? ['barangay' => $reservation->usage_barangay] : [])
            + ['resource' => $reservation->facility_name, 'type' => $reservation->usage_type,
                'approved' => $equipment ? ($usage?->approved_quantity ?? $reservation->quantity ?? 'Unverified') : '-',
                'released' => $equipment ? ($usage?->released_quantity ?? 0) : '-', 'returned' => $equipment ? ($usage?->returned_quantity ?? 0) : '-',
                'outstanding' => $equipment ? ($usage?->outstanding() ?? 0) : '-',
                'scheduled_start' => $reservation->period()->start->format('Y-m-d H:i'), 'scheduled_end' => $reservation->period()->end->format('Y-m-d H:i'),
                'actual_start' => $usage?->actual_started_at?->format('Y-m-d H:i:s') ?? '-', 'actual_end' => $usage?->actual_ended_at?->format('Y-m-d H:i:s') ?? '-',
                'status' => ResourceUsage::STATUSES[$reservation->usage_status], 'overdue' => $usage?->overdue() ? 'Overdue' : '-'];
    }

    public static function download(Request $request, string $format)
    {
        $query = ResourceUsageQuery::forRequest($request);
        abort_if((clone $query)->count() > 5000, 422, 'Narrow the filters to 5,000 or fewer records before exporting.');
        $systemWide = $request->user()->role === 'super_admin';
        $columns = self::columns($systemWide);
        $types = ['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

        return response()->streamDownload(function () use ($query, $columns, $systemWide, $format, $request) {
            DB::transaction(function () use ($query, $columns, $systemWide, $format, $request) {
                $rows = $query->limit(5000)->get()->map(fn ($reservation) => self::row($reservation, $systemWide));
                if ($format === 'pdf') {
                    $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'tempDir' => sys_get_temp_dir(), 'fontCache' => storage_path('framework/cache')]));
                    $pdf->setPaper('A3', 'landscape');
                    $pdf->loadHtml(view('shared.resource-usage.report', compact('rows', 'columns', 'request'))->render());
                    $pdf->render();
                    echo $pdf->output();
                } else {
                    $book = new Spreadsheet;
                    $sheet = $book->getActiveSheet()->setTitle('Resource Usage');
                    $sheet->fromArray(array_values($columns), null, 'A1');
                    foreach ($rows as $index => $row) {
                        foreach (array_values($row) as $column => $value) {
                            $sheet->setCellValueExplicit([$column + 1, $index + 2], (string) $value, DataType::TYPE_STRING);
                        }
                    }
                    $sheet->freezePane('A2');
                    $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
                    $sheet->getStyle('1:1')->getFont()->setBold(true);
                    foreach (range(1, count($columns)) as $column) {
                        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(23);
                    }
                    try {
                        (new Xlsx($book))->save('php://output');
                    } finally {
                        $book->disconnectWorksheets();
                    }
                }
            });
        }, 'ereserve-resource-usage-'.today()->toDateString().'.'.$format, ['Content-Type' => $types[$format], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

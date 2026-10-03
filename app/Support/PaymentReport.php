<?php

namespace App\Support;

use App\Models\Payment;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentReport
{
    public const COLUMNS = ['Payment ID', 'Reservation ID', 'Resident', 'Resource', 'Reservation Date', 'Total', 'Payment Status'];

    public static function row(Payment $payment): array
    {
        $reservation = $payment->reservation;

        return [$payment->id, $reservation->id, $reservation->user?->name ?? 'Former resident',
            $reservation->facility?->name ?? $reservation->facility_name, $reservation->reservation_date,
            $reservation->total, ucfirst($payment->payment_status)];
    }

    public static function metadata(Request $request): array
    {
        return [
            'Barangay' => $request->user()->role === 'super_admin' ? 'All Barangays' : $request->user()->barangay,
            'Report Period' => ($request->query('from_date') ?: 'Beginning').' to '.($request->query('to_date') ?: 'Present'),
            'Date Basis' => 'Payment recorded date',
            'Payment Status' => ucfirst($request->query('status', 'all') ?: 'all'),
            'Search' => trim((string) $request->query('search', '')) ?: 'All records',
            'Generated' => now()->format('Y-m-d H:i:s T'),
        ];
    }

    public static function download(Request $request, string $format): StreamedResponse
    {
        $type = ['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=UTF-8'][$format];

        return response()->streamDownload(function () use ($request, $format) {
            // Read summary and rows from one consistent database transaction.
            DB::transaction(function () use ($request, $format) {
                $query = PaymentQuery::forRequest($request);
                $summary = PaymentQuery::summary($query);
                $metadata = self::metadata($request);
                if ($format === 'pdf') {
                    $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'tempDir' => sys_get_temp_dir(), 'fontCache' => storage_path('framework/cache')]);
                    $pdf = new Dompdf($options);
                    $pdf->setPaper('A4', 'landscape');
                    $pdf->loadHtml(view('payments.report', ['rows' => $query->lazy(500)->map(fn ($payment) => self::row($payment)), 'summary' => $summary, 'metadata' => $metadata])->render());
                    $pdf->render();
                    $pdf->getCanvas()->page_text(740, 565, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8);
                    echo $pdf->output();
                } elseif ($format === 'csv') {
                    $output = fopen('php://output', 'wb');
                    fwrite($output, "\xEF\xBB\xBF");
                    fputcsv($output, self::COLUMNS, ',', '"', '');
                    foreach ($query->lazy(500) as $payment) {
                        // Prevent spreadsheet formula execution while retaining numeric totals.
                        $row = array_map(fn ($value) => is_string($value) && preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value, self::row($payment));
                        fputcsv($output, $row, ',', '"', '');
                    }
                    fclose($output);
                } else {
                    $book = new Spreadsheet;
                    $sheet = $book->getActiveSheet()->setTitle('Payments');
                    $sheet->fromArray(self::COLUMNS, null, 'A1');
                    $index = 2;
                    foreach ($query->lazy(500) as $payment) {
                        foreach (self::row($payment) as $column => $value) {
                            $numeric = $column < 2 || $column === 5;
                            if ($value !== null) {
                                $sheet->setCellValueExplicit([$column + 1, $index], $numeric ? (float) $value : (string) $value, $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                            }
                        }
                        $index++;
                    }
                    $sheet->getStyle('A1:G1')->getFont()->setBold(true);
                    if ($index > 2) {
                        $sheet->getStyle('F2:F'.($index - 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                    }
                    $sheet->setAutoFilter('A1:G'.max(1, $index - 1));
                    $sheet->freezePane('A2');
                    foreach (['A' => 14, 'B' => 18, 'C' => 30, 'D' => 30, 'E' => 22, 'F' => 18, 'G' => 20] as $column => $width) {
                        $sheet->getColumnDimension($column)->setWidth($width);
                    }
                    $info = $book->createSheet()->setTitle('Report');
                    $info->fromArray([['eReserve', 'Payment Report'], ...array_map(fn ($key, $value) => [$key, $value], array_keys($metadata), array_values($metadata)), ['Total Payments', $summary['count']], ['Total', (float) $summary['total']], ['Totals not recorded', $summary['unrecorded']]], null, 'A1', true);
                    // Metadata can contain a user's search string, so force it to text.
                    foreach ($metadata as $key => $value) {
                        $rowIndex = array_search($key, array_keys($metadata), true) + 2;
                        $info->setCellValueExplicit('B'.$rowIndex, $value, DataType::TYPE_STRING);
                    }
                    $info->getColumnDimension('A')->setWidth(24);
                    $info->getColumnDimension('B')->setWidth(55);
                    $book->setActiveSheetIndex(0);
                    try {
                        (new Xlsx($book))->save('php://output');
                    } finally {
                        $book->disconnectWorksheets();
                    }
                }
            });
        }, 'ereserve-payment-report-'.today()->toDateString().'.'.$format, ['Content-Type' => $type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

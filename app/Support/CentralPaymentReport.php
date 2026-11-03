<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CentralPaymentReport
{
    public static function columns(bool $includeBarangay): array
    {
        $columns = ['Payment ID', 'Resident', 'Payment Type', 'Reference', 'Amount', 'Amount Paid', 'Balance', 'Date', 'Status'];
        if ($includeBarangay) {
            $columns[] = 'Barangay';
        }

        return $columns;
    }

    public static function row(object $payment, bool $includeBarangay): array
    {
        $charge = $payment->payment_type === 'equipment_charge';
        $amountCents = Money::cents((string) $payment->total_amount);
        $paidCents = Money::cents((string) $payment->paid_amount);
        $balanceCents = $charge
            ? Money::cents((string) $payment->balance_amount)
            : ($payment->status === 'pending' ? max(0, $amountCents - $paidCents) : 0);
        $row = [
            ($charge ? 'CHG-#' : 'PAY-#').($charge ? $payment->charge_id : $payment->payment_id),
            $payment->resident_name ?: 'Former resident',
            $charge ? 'Equipment Charge' : 'Reservation',
            $charge ? 'Charge #'.$payment->charge_id : 'Reservation #'.$payment->reservation_id,
            $payment->total_amount === null ? null : Money::decimal($amountCents),
            Money::decimal($paidCents),
            Money::decimal($balanceCents),
            $payment->payment_date ? Carbon::parse($payment->payment_date)->format('Y-m-d') : '',
            \App\Models\Payment::statusLabel($payment->status),
        ];
        if ($includeBarangay) {
            $row[] = $payment->barangay;
        }

        return $row;
    }

    public static function metadata(Request $request): array
    {
        return [
            'Barangay' => $request->user()->role === 'super_admin' ? ($request->query('barangay') ?: 'All Barangays') : $request->user()->barangay,
            'Payment Type' => match ($request->query('type', 'all')) {
                'reservation' => 'Reservation',
                'equipment_charge' => 'Equipment Charge',
                default => 'All Payments',
            },
            'Report Period' => ($request->query('from_date') ?: 'Beginning').' to '.($request->query('to_date') ?: 'Present'),
            'Payment Status' => \App\Models\Payment::statusLabel($request->query('status', 'all') ?: 'all'),
            'Search' => trim((string) $request->query('search', '')) ?: 'All records',
            'Generated' => now()->format('Y-m-d H:i:s T'),
        ];
    }

    public static function download(Request $request, string $format): StreamedResponse
    {
        $contentType = $format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->streamDownload(function () use ($request, $format) {
            DB::transaction(function () use ($request, $format) {
                $query = CentralPaymentQuery::forRequest($request);
                $summaryRow = (clone $query)->reorder()->selectRaw(
                    'COUNT(*) as records, COALESCE(SUM(ROUND(total_amount * 100)),0) as total_cents, SUM(CASE WHEN payment_type = ? AND total_amount IS NULL THEN 1 ELSE 0 END) as unrecorded',
                    ['reservation']
                )->first();
                $summary = [
                    'count' => (int) $summaryRow->records,
                    'total' => Money::decimal((int) $summaryRow->total_cents),
                    'unrecorded' => (int) $summaryRow->unrecorded,
                ];
                $metadata = self::metadata($request);
                $includeBarangay = $request->user()->role === 'super_admin';
                $columns = self::columns($includeBarangay);
                $rows = $query->lazy(500)->map(fn (object $payment) => self::row($payment, $includeBarangay));

                if ($format === 'pdf') {
                    $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'tempDir' => sys_get_temp_dir(), 'fontCache' => storage_path('framework/cache')]);
                    $pdf = new Dompdf($options);
                    $pdf->setPaper('A4', 'landscape');
                    $pdf->loadHtml(view('shared.payments.central-report', compact('rows', 'summary', 'metadata', 'columns'))->render());
                    $pdf->render();
                    $pdf->getCanvas()->page_text(740, 565, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8);
                    echo $pdf->output();

                    return;
                }

                $book = new Spreadsheet;
                $sheet = $book->getActiveSheet()->setTitle('Payments');
                $sheet->fromArray($columns, null, 'A1');
                $index = 2;
                $moneyIndexes = [4, 5, 6];
                foreach ($rows as $row) {
                    foreach ($row as $column => $value) {
                        if ($value !== null && in_array($column, $moneyIndexes, true)) {
                            $sheet->setCellValueExplicit([$column + 1, $index], (float) $value, DataType::TYPE_NUMERIC);
                        } elseif ($value !== null) {
                            $sheet->setCellValueExplicit([$column + 1, $index], (string) $value, DataType::TYPE_STRING);
                        }
                    }
                    $index++;
                }
                $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
                $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
                if ($index > 2) {
                    foreach ($moneyIndexes as $moneyIndex) {
                        $letter = Coordinate::stringFromColumnIndex($moneyIndex + 1);
                        $sheet->getStyle($letter.'2:'.$letter.($index - 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                    }
                }
                $sheet->setAutoFilter('A1:'.$lastColumn.max(1, $index - 1));
                $sheet->freezePane('A2');
                foreach ([18, 26, 22, 32, 16, 16, 16, 16, 20, 22] as $column => $width) {
                    if ($column < count($columns)) {
                        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column + 1))->setWidth($width);
                    }
                }
                $info = $book->createSheet()->setTitle('Report');
                $info->fromArray([['eReserve', 'Central Payment Report'], ...array_map(fn ($key, $value) => [$key, $value], array_keys($metadata), array_values($metadata)), ['Total Records', $summary['count']], ['Total Amount', (float) $summary['total']], ['Reservation totals not recorded', $summary['unrecorded']]], null, 'A1', true);
                foreach ($metadata as $key => $value) {
                    $rowIndex = array_search($key, array_keys($metadata), true) + 2;
                    $info->setCellValueExplicit('B'.$rowIndex, (string) $value, DataType::TYPE_STRING);
                }
                $info->getColumnDimension('A')->setWidth(32);
                $info->getColumnDimension('B')->setWidth(55);
                $book->setActiveSheetIndex(0);
                try {
                    (new Xlsx($book))->save('php://output');
                } finally {
                    $book->disconnectWorksheets();
                }
            });
        }, 'ereserve-central-payment-report-'.today()->toDateString().'.'.$format, ['Content-Type' => $contentType, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

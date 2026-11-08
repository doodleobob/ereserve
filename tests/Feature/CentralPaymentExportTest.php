<?php

namespace Tests\Feature;

use App\Models\EquipmentCharge;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CentralPaymentExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $resident;
    private Facility $equipment;
    private Reservation $reservation;
    private EquipmentCharge $charge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->resident = User::factory()->create(['role' => 'user', 'name' => 'Export Resident', 'barangay' => 'Washington']);
        $this->equipment = Facility::create(['barangay' => 'Taft', 'slug' => 'export-chairs', 'name' => 'Export Chairs', 'category' => 'Equipment', 'description' => 'Chairs', 'total_quantity' => 20, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 0]);
        $this->reservation = Reservation::create(['user_id' => $this->resident->id, 'barangay' => 'Taft', 'facility_id' => $this->equipment->id, 'facility_slug' => $this->equipment->slug, 'facility_name' => $this->equipment->name, 'category' => 'Equipment', 'location' => 'Taft', 'reservation_date' => '2026-11-03', 'start_time' => '10:00', 'end_time' => '12:00', 'purpose' => 'Community activity', 'quantity' => 10, 'status' => 'accepted', 'total_payment' => '125.00']);
        $this->reservation->payment()->create(['amount' => '125.00', 'payment_status' => 'paid']);
        $usage = $this->reservation->resourceUsage()->create(['facility_id' => $this->equipment->id, 'barangay' => 'Taft', 'resource_type' => 'Equipment', 'approved_quantity' => 10, 'released_quantity' => 10, 'returned_quantity' => 0]);
        $this->charge = EquipmentCharge::create(['resource_usage_id' => $usage->id, 'created_by' => $this->admin->id, 'quantity' => 2, 'amount' => '80.00', 'reason' => 'Damaged Equipment', 'status' => 'unpaid', 'request_key' => (string) Str::uuid()]);
        $this->actingAs($this->admin)->post(route('equipment-charges.payments.store', $this->charge), ['amount' => '30.00', 'payment_date' => '2026-11-03', 'request_key' => (string) Str::uuid()])->assertRedirect();
    }

    private function download(string $format, array $filters = [])
    {
        return $this->actingAs($this->admin)->get(route('payments.export-centralized', ['format' => $format, ...$filters]))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    private function workbook(string $bytes)
    {
        $path = tempnam(sys_get_temp_dir(), 'central-payment-');
        file_put_contents($path, $bytes);
        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $text = '';
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded === false || ! str_contains($decoded, 'BT ')) {
                continue;
            }
            preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/s', $decoded, $strings);
            foreach ($strings[1] as $literal) {
                $literal = preg_replace_callback('/\\\\([0-7]{1,3}|.)/s', fn ($m) => ctype_digit($m[1]) ? chr(octdec($m[1])) : match ($m[1]) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", default => $m[1],
                }, $literal);
                if (strlen($literal) % 2 === 0) {
                    $text .= mb_convert_encoding($literal, 'UTF-8', 'UTF-16BE').' ';
                }
            }
        }

        return preg_replace('/\s+/u', ' ', $text);
    }

    public function test_payments_download_menu_only_offers_pdf_and_excel_and_unpaid_badge_is_yellow(): void
    {
        $this->get(route('payments.index', ['type' => 'equipment_charge']))->assertOk()
            ->assertSee('Download PDF')->assertSee('Download Excel')->assertDontSee('Download CSV')
            ->assertSee('payment-status-unpaid', false);
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString('[data-payment-table] .payment-status-unpaid {background:#fef3c7;color:#92400e}', $css);
        $this->assertStringContainsString('.charge-status-unpaid { background:#fef3c7; color:#92400e; }', $css);
        $this->assertStringContainsString('.payment-status-paid, .reservation-status-accepted {background:#dcfce7;color:#166534}', $css);
        $this->assertStringContainsString('.payment-status-refunded {background:#e0e7ff;color:#3730a3}', $css);
    }

    public function test_excel_export_contains_reservation_and_equipment_charge_rows_and_balances(): void
    {
        $response = $this->download('xlsx');
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition');
        $sheet = $this->workbook($response->streamedContent())->getSheetByName('Payments');
        $this->assertSame(['Payment ID', 'Resident', 'Payment Type', 'Reference', 'Amount', 'Amount Paid', 'Balance', 'Date', 'Status'], $sheet->rangeToArray('A1:I1')[0]);
        $rows = $sheet->rangeToArray('A2:I3');
        $chargeRow = collect($rows)->first(fn ($row) => $row[2] === 'Equipment Charge');
        $reservationRow = collect($rows)->first(fn ($row) => $row[2] === 'Reservation');
        $this->assertSame('CHG-#'.$this->charge->id, $chargeRow[0]);
        $this->assertSame('Export Resident', $chargeRow[1]);
        $this->assertSame('80.00', $chargeRow[4]);
        $this->assertSame('30.00', $chargeRow[5]);
        $this->assertSame('50.00', $chargeRow[6]);
        $this->assertSame('Unpaid', $chargeRow[8]);
        $this->assertSame('PAY-#1', $reservationRow[0]);
        $this->assertSame('Reservation', $reservationRow[2]);
        $this->assertSame('Paid', $reservationRow[8]);
    }

    public function test_successful_payment_report_exports_are_audited_without_search_or_payment_details(): void
    {
        $before = \App\Models\AuditLogEntry::where('action', 'payment.report_exported')->count();
        $response = $this->download('xlsx', ['type' => 'reservation', 'status' => 'paid', 'search' => 'private-search-term']);
        $this->assertSame($before, \App\Models\AuditLogEntry::where('action', 'payment.report_exported')->count());
        $response->streamedContent();
        $entry = \App\Models\AuditLogEntry::where('action', 'payment.report_exported')->firstOrFail();
        $this->assertSame($this->admin->id, $entry->actor_id);
        $this->assertSame('admin', $entry->actor_role);
        $this->assertSame('Taft', $entry->barangay);
        $this->assertSame(['report_type' => 'centralized_payments', 'format' => 'xlsx', 'filters' => ['type' => 'reservation', 'status' => 'paid']], $entry->after_values);
        $this->assertStringNotContainsString('private-search-term', json_encode($entry->after_values));
        $this->assertNull($entry->target_id);

        $super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $response = $this->actingAs($super)->get(route('payments.export-centralized', ['format' => 'pdf', 'barangay' => 'Washington']))->assertOk();
        $response->streamedContent();
        $this->assertDatabaseHas('audit_log_entries', ['actor_id' => $super->id, 'actor_role' => 'super_admin', 'barangay' => 'Washington', 'action' => 'payment.report_exported']);

        $standard = $this->actingAs($this->admin)->get(route('payments.export', ['format' => 'csv', 'status' => 'paid']))->assertOk();
        $standard->streamedContent();
        $this->assertDatabaseHas('audit_log_entries', [
            'actor_id' => $this->admin->id, 'actor_role' => 'admin', 'barangay' => 'Taft',
            'action' => 'payment.report_exported',
        ]);
        $standardEntry = \App\Models\AuditLogEntry::where('action', 'payment.report_exported')->where('actor_id', $this->admin->id)->latest('id')->firstOrFail();
        $this->assertSame('payments', $standardEntry->after_values['report_type']);
    }

    public function test_invalid_payment_export_does_not_create_a_success_audit_event(): void
    {
        $this->actingAs($this->admin)->get(route('payments.export-centralized', ['format' => 'csv']))->assertRedirect();
        $this->assertDatabaseMissing('audit_log_entries', ['action' => 'payment.report_exported']);
    }

    public function test_pdf_and_excel_exports_apply_the_central_page_type_status_and_search_filters(): void
    {
        $filters = ['type' => 'equipment_charge', 'status' => 'unpaid', 'search' => 'CHG-'.$this->charge->id];
        $pdf = $this->download('pdf', $filters)->assertHeader('Content-Type', 'application/pdf')->streamedContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $pdfText = $this->pdfText($pdf);
        $this->assertStringContainsString('Export Resident', $pdfText);
        $this->assertStringContainsString('Equipment Charge', $pdfText);
        $this->assertStringNotContainsString('PAY-#1', $pdfText);

        $xlsx = $this->download('xlsx', ['type' => 'reservation'])->streamedContent();
        $sheet = $this->workbook($xlsx)->getSheetByName('Payments');
        $this->assertSame('Reservation', $sheet->getCell('C2')->getValue());
        $this->assertNull($sheet->getCell('C3')->getValue());
    }

    public function test_residents_cannot_download_admin_payment_reports(): void
    {
        $this->actingAs($this->resident)->get(route('payments.export-centralized', ['format' => 'pdf']))->assertForbidden();
        $this->actingAs($this->resident)->get(route('payments.export-centralized', ['format' => 'xlsx']))->assertForbidden();
    }

    public function test_super_admin_can_export_equipment_charge_records_across_barangays(): void
    {
        $foreignEquipment = Facility::create(['barangay' => 'Washington', 'slug' => 'foreign-export-chairs', 'name' => 'Foreign Export Chairs', 'category' => 'Equipment', 'description' => 'Chairs', 'total_quantity' => 20, 'location' => 'Washington', 'status' => 'Available', 'hourly_rate' => 0]);
        $foreignReservation = Reservation::create(['user_id' => $this->resident->id, 'barangay' => 'Taft', 'facility_id' => $foreignEquipment->id, 'facility_slug' => $foreignEquipment->slug, 'facility_name' => $foreignEquipment->name, 'category' => 'Equipment', 'location' => 'Washington', 'reservation_date' => '2026-11-03', 'start_time' => '10:00', 'end_time' => '12:00', 'purpose' => 'Community activity', 'quantity' => 10, 'status' => 'accepted']);
        $usage = $foreignReservation->resourceUsage()->create(['facility_id' => $foreignEquipment->id, 'barangay' => 'Washington', 'resource_type' => 'Equipment', 'approved_quantity' => 10, 'released_quantity' => 10, 'returned_quantity' => 0]);
        $foreignCharge = EquipmentCharge::create(['resource_usage_id' => $usage->id, 'created_by' => $this->admin->id, 'quantity' => 2, 'amount' => '80.00', 'reason' => 'Damaged Equipment', 'status' => 'unpaid', 'request_key' => (string) Str::uuid()]);
        $super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $bytes = $this->actingAs($super)->get(route('payments.export-centralized', ['format' => 'xlsx', 'type' => 'equipment_charge']))->assertOk()->streamedContent();
        $rows = $this->workbook($bytes)->getSheetByName('Payments')->toArray();
        $this->assertContains('CHG-#'.$foreignCharge->id, array_column($rows, 0));

        $scoped = $this->actingAs($super)->get(route('payments.export-centralized', ['format' => 'xlsx', 'type' => 'equipment_charge', 'barangay' => 'Washington']))->assertOk()->streamedContent();
        $scopedRows = $this->workbook($scoped)->getSheetByName('Payments')->toArray();
        $this->assertSame(['CHG-#'.$foreignCharge->id], array_values(array_filter(array_column($scopedRows, 0), fn ($value) => is_string($value) && str_starts_with($value, 'CHG-#'))));
    }
}

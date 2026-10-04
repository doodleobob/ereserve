<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Money;
use App\Support\PaymentQuery;
use App\Support\PaymentReport;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PaymentsManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $resident;

    private Facility $court;

    private Facility $foreignCourt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo(Carbon::parse('2026-10-02 10:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        // The resident's barangay does not control resource management ownership.
        $this->resident = User::factory()->create(['name' => 'Juan Dela Cruz', 'barangay' => 'Washington']);
        foreach (['court' => 'Taft', 'foreignCourt' => 'Washington'] as $field => $barangay) {
            $this->{$field} = Facility::create(['barangay' => $barangay, 'slug' => $field, 'name' => $field === 'court' ? 'Covered Court' : 'Secret Court', 'category' => 'Facility', 'description' => 'Court', 'capacity' => 50, 'location' => $barangay, 'status' => 'Available', 'hourly_rate' => 100]);
        }
        $this->actingAs($this->admin);
    }

    private function reservation(array $data = []): Reservation
    {
        return Reservation::create([...[
            'user_id' => $this->resident->id, 'facility_id' => $this->court->id, 'barangay' => 'Taft', 'facility_slug' => 'court',
            'facility_name' => 'Old Court Name', 'category' => 'Facility', 'location' => 'Taft', 'reservation_date' => '2026-10-10',
            'start_time' => '22:00', 'end_time' => '02:00', 'purpose' => 'Meeting', 'attendees' => 10, 'status' => 'accepted',
            'hourly_rate_snapshot' => 100, 'total_payment' => '1250.25',
        ], ...$data]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // PDF renderer object graphs can outlive a response in the shared test process.
        gc_collect_cycles();
    }

    public function test_reservation_total_accessor_preserves_existing_dashboard_and_analytics_count_aliases(): void
    {
        $booking = $this->reservation();
        $this->reservation(['status' => 'pending', 'total_payment' => '0.00']);
        $this->assertSame('1250.25', $booking->total);
        $this->assertSame(2, Reservation::selectRaw('COUNT(*) AS total')->first()->total);
        $counts = Reservation::selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $this->assertSame(1, $counts['pending']);
        $this->assertSame(1, $counts['accepted']);
        $this->assertSame('0.00', Reservation::where('status', 'pending')->first()->total);
    }

    private function payment(array $booking = [], array $data = []): Payment
    {
        $payment = $this->reservation($booking)->payment()->create(['amount' => '7.00', 'payment_status' => 'paid', ...$data]);
        $payment->forceFill(['created_at' => $data['created_at'] ?? '2026-10-01 12:00:00'])->save();

        return $payment;
    }

    private function edit(Payment $payment, string $status, array $extra = [])
    {
        return $this->patchJson(route('payments.update', $payment), ['payment_status' => $status, 'receipt_confirmed' => 1, ...$extra]);
    }

    private function export(string $format, array $filters = []): string
    {
        return $this->get(route('payments.export', ['format' => $format, ...$filters]))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
    }

    private function spreadsheet(string $bytes)
    {
        $path = tempnam(sys_get_temp_dir(), 'payment-test-');
        file_put_contents($path, $bytes);
        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    private function pdfText(string $pdf): string
    {
        // Decode Dompdf's compressed text streams; assert the downloaded document itself.
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
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c", default => $m[1],
                }, $literal);
                if (strlen($literal) % 2 === 0) {
                    $text .= mb_convert_encoding($literal, 'UTF-8', 'UTF-16BE').' ';
                }
            }
        }

        return preg_replace('/\s+/u', ' ', $text);
    }

    public function test_authorization_private_page_and_tenant_scope_cover_every_endpoint(): void
    {
        $ours = $this->payment();
        $foreign = $this->payment(['facility_id' => $this->foreignCourt->id, 'barangay' => 'Taft']);
        $this->get(route('payments.index', ['barangay_id' => 2, 'barangay' => 'Washington']))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('Secret Court')->assertViewHas('payments', fn ($rows) => $rows->total() === 1);
        $this->edit($foreign, 'refunded')->assertForbidden();
        foreach (['pdf', 'xlsx', 'csv'] as $format) {
            $this->get(route('payments.export', ['format' => $format, 'search' => 'Secret Court', 'barangay' => 'Washington']))->assertOk();
        }
        $this->actingAs($this->resident)->get(route('payments.index'))->assertForbidden();
        $this->edit($ours, 'refunded')->assertForbidden();
        foreach (['pdf', 'xlsx', 'csv'] as $format) {
            $this->get(route('payments.export', ['format' => $format]))->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->get(route('payments.index'))->assertOk()->assertSee('Secret Court')->assertViewHas('payments', fn ($rows) => $rows->total() === 2);
        $this->edit($foreign, 'refunded')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']))->get(route('payments.index'))->assertViewHas('payments', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $foreign->id);
        $this->edit($foreign, 'refunded')->assertOk();
        $this->edit($ours, 'refunded')->assertForbidden();
        $this->patchJson('/payments/99999', ['payment_status' => 'paid'])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get(route('payments.index'))->assertRedirect(route('login'));
    }

    public function test_table_and_modals_use_current_relationship_total_and_readonly_reservation_details(): void
    {
        $payment = $this->payment();
        $response = $this->get(route('payments.index'))->assertOk()->assertSee('Juan Dela Cruz')->assertSee('Covered Court')->assertSee('Oct 10, 2026')->assertSee('₱1,250.25')->assertSee('Payment Details')->assertSee('Edit Payment')->assertSee('Paid')->assertDontSee('₱7.00')->assertDontSee('Old Court Name')->assertDontSee('Amount')->assertDontSee('Delete');
        $html = $response->getContent();
        $this->assertSame(8, substr_count($html, '<th scope="col"'));
        foreach (['Payment ID', 'Reservation ID', 'Resident', 'Resource', 'Reservation Date', 'Reservation Time', 'Total', 'Payment Status'] as $label) {
            $this->assertStringContainsString('<dt>'.$label.'</dt>', $html);
        }
        foreach (['total', 'total_payment', 'reservation_date', 'start_time', 'end_time', 'facility_id', 'user_id'] as $name) {
            $this->assertStringNotContainsString('name="'.$name.'"', $html);
        }
        $this->assertStringContainsString('data-reservation-open="payment-view-'.$payment->id.'"', $html);
        $this->assertStringContainsString('data-reservation-open="payment-edit-'.$payment->id.'"', $html);
        $this->assertStringContainsString('Oct 11', $html);
        $this->assertSame('1250.25', $payment->reservation->total);
        $this->assertSame($payment->id, $payment->reservation->payment->id);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_search_ids_resident_current_resource_and_legacy_resource(): void
    {
        $payment = $this->payment();
        $legacy = $this->payment(['facility_id' => null, 'facility_name' => 'Historic Chairs']);
        foreach (['PAY-'.$payment->id, 'pay-00'.$payment->id, '#'.$payment->id, 'RES-'.$payment->reservation_id, 'Covered Court'] as $search) {
            $this->get(route('payments.index', ['search' => $search]))->assertViewHas('payments', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $payment->id);
        }
        $this->get(route('payments.index', ['search' => 'Juan']))->assertViewHas('payments', fn ($rows) => $rows->total() === 2);
        $this->get(route('payments.index', ['search' => 'Historic Chairs']))->assertViewHas('payments', fn ($rows) => $rows->first()->id === $legacy->id);
        $this->get(route('payments.index', ['search' => "' OR 1=1 --"]))->assertViewHas('payments', fn ($rows) => $rows->total() === 0)->assertSee('No payment records found.');
    }

    public function test_payment_recorded_date_boundaries_status_and_combined_filters_match_summary(): void
    {
        $first = $this->payment(['total_payment' => '10.10'], ['created_at' => '2026-09-30 23:59:59']);
        $second = $this->payment(['total_payment' => '20.20'], ['created_at' => '2026-10-01 00:00:00']);
        $third = $this->payment(['total_payment' => '30.30'], ['created_at' => '2026-10-01 23:59:59', 'payment_status' => 'refunded']);
        $fourth = $this->payment(['total_payment' => '40.40'], ['created_at' => '2026-10-02 00:00:00']);
        foreach ([['from_date' => '2026-10-01'], ['to_date' => '2026-10-01'], ['from_date' => '2026-10-01', 'to_date' => '2026-10-01'], ['search' => 'Court', 'status' => 'paid', 'from_date' => '2026-10-01', 'to_date' => '2026-10-01']] as $index => $filters) {
            $ids = [[$second->id, $third->id, $fourth->id], [$first->id, $second->id, $third->id], [$second->id, $third->id], [$second->id]][$index];
            $sum = ['90.90', '60.60', '50.50', '20.20'][$index];
            $this->get(route('payments.index', $filters))->assertViewHas('payments', fn ($rows) => $rows->pluck('id')->sort()->values()->all() === $ids)->assertViewHas('summary', fn ($summary) => $summary['count'] === count($ids) && $summary['total'] === $sum);
        }
        $this->get(route('payments.index', ['from_date' => '2026-10-11']))->assertSee('No payment records found.')->assertViewHas('summary', fn ($s) => $s['count'] === 0 && $s['total'] === '0.00');
    }

    public function test_pagination_rows_sorting_and_summary_use_every_filtered_record(): void
    {
        foreach (range(1, 27) as $index) {
            $this->payment(['total_payment' => Money::decimal($index * 100)], ['payment_status' => $index % 2 ? 'paid' : 'refunded']);
        }
        $this->get(route('payments.index'))->assertViewHas('payments', fn ($rows) => $rows->total() === 27 && $rows->count() === 10)->assertViewHas('summary', fn ($s) => $s['count'] === 27 && $s['total'] === '378.00');
        foreach ([10, 25, 50, 100] as $size) {
            $this->get(route('payments.index', ['per_page' => $size]))->assertViewHas('payments', fn ($rows) => $rows->count() === min(27, $size));
        }
        $this->get(route('payments.index', ['page' => 2, 'status' => 'paid']))->assertViewHas('payments', fn ($rows) => $rows->count() === 4 && str_contains($rows->previousPageUrl(), 'status=paid'));
        $this->get(route('payments.index', ['page' => 999]))->assertViewHas('payments', fn ($rows) => $rows->currentPage() === 3 && $rows->count() === 7);
        foreach (['id', 'reservation', 'resident', 'resource', 'date', 'total', 'status'] as $sort) {
            $this->get(route('payments.index', ['sort' => $sort, 'direction' => 'asc']))->assertOk();
        }
        $this->get(route('payments.index', ['sort' => 'total', 'direction' => 'asc']))->assertViewHas('payments', fn ($rows) => $rows->first()->reservation->total === '1.00');
        $this->get(route('payments.index', ['sort' => 'total', 'direction' => 'desc']))->assertViewHas('payments', fn ($rows) => $rows->first()->reservation->total === '27.00');
    }

    public function test_parameter_validation_and_nullable_filters_on_page_and_exports(): void
    {
        foreach ([['search' => str_repeat('x', 201)], ['status' => 'accepted'], ['from_date' => 'bad'], ['to_date' => '2026-02-30'], ['from_date' => '2026-10-02', 'to_date' => '2026-10-01'], ['sort' => 'total;drop'], ['direction' => 'evil'], ['per_page' => 11], ['page' => 0]] as $filters) {
            $this->getJson(route('payments.index', $filters))->assertUnprocessable();
            foreach (['pdf', 'xlsx', 'csv'] as $format) {
                $this->getJson(route('payments.export', ['format' => $format, ...$filters]))->assertUnprocessable();
            }
        }
        foreach (['html', '../file', 'php', null] as $format) {
            $this->getJson(route('payments.export', ['format' => $format]))->assertUnprocessable();
        }
        $this->get(route('payments.index', ['sort' => '', 'direction' => '', 'status' => '', 'per_page' => '']))->assertOk();
    }

    public function test_status_edit_preserves_entire_reservation_snapshot_and_payment_financial_snapshot(): void
    {
        $payment = $this->payment(['status' => 'cancelled']);
        $before = $payment->reservation->fresh()->toArray();
        $this->edit($payment, 'refunded', ['total' => 999, 'total_payment' => 999, 'amount' => 999, 'reservation_id' => 999, 'reservation_date' => '2026-11-01', 'status' => 'accepted', 'refunded_by' => 999])->assertOk()->assertJsonPath('message', 'Payment updated successfully.');
        $payment->refresh();
        $this->assertSame($before, $payment->reservation->fresh()->toArray());
        $this->assertSame('7.00', $payment->amount);
        $this->assertSame('refunded', $payment->payment_status);
        $this->assertSame($this->admin->id, $payment->refunded_by);
        $this->assertNotNull($payment->refunded_at);
        $this->assertSame(1, $payment->revision);
        $this->assertSame('paid', $payment->history[0]['before']);
        $this->assertSame('refunded', $payment->history[0]['after']);
        $this->assertDatabaseCount('payments', 1);
        $snapshot = $payment->withoutRelations()->toArray();
        $this->edit($payment, 'refunded')->assertOk();
        $this->assertSame($snapshot, $payment->fresh()->toArray());
        $this->deleteJson('/payments/'.$payment->id)->assertStatus(405);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_valid_transitions_confirmation_terminal_statuses_and_invalid_edits(): void
    {
        $payment = $this->payment([], ['payment_status' => 'pending']);
        $snapshot = $payment->fresh()->toArray();
        $this->patchJson(route('payments.update', $payment), [])->assertUnprocessable();
        $this->edit($payment, 'invalid')->assertUnprocessable();
        $this->edit($payment, 'refunded')->assertUnprocessable();
        $this->patchJson(route('payments.update', $payment), ['payment_status' => 'paid'])->assertUnprocessable()->assertJsonValidationErrors('receipt_confirmed');
        $this->assertSame($snapshot, $payment->fresh()->toArray());
        $this->edit($payment, 'paid')->assertOk();
        $this->edit($payment, 'pending')->assertUnprocessable();
        $this->patchJson(route('payments.update', $payment), ['payment_status' => 'refunded', 'receipt_confirmed' => 0])->assertUnprocessable();
        $this->edit($payment, 'refunded')->assertOk();
        $this->edit($payment, 'paid')->assertUnprocessable();
        $cancelled = $this->payment([], ['payment_status' => 'pending']);
        $this->patchJson(route('payments.update', $cancelled), ['payment_status' => 'cancelled'])->assertOk();
        $this->edit($cancelled, 'paid')->assertUnprocessable();
        $this->get(route('payments.index'))->assertOk()->assertSee('This payment is closed.');
    }

    public function test_acceptance_creates_one_payment_and_reservation_corrections_leave_history_intact(): void
    {
        $r = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        $this->postJson(route('reservations.accept', $r), ['total_payment' => '400.00', 'payment_confirmed' => 1])->assertOk();
        $payment = $r->fresh()->payment;
        $this->assertSame('paid', $payment->payment_status);
        $this->assertSame('400.00', $payment->amount);
        $before = $payment->fresh()->toArray();
        $this->postJson(route('reservations.accept', $r), ['total_payment' => '999.00', 'payment_confirmed' => 1])->assertUnprocessable();
        $this->patchJson(route('reservations.payment', $r), ['total_payment' => '450.00'])->assertOk();
        $this->assertSame($before, $payment->fresh()->toArray());
        $this->assertSame('450.00', $r->fresh()->total);
        $this->assertDatabaseCount('payments', 1);
        $this->get(route('payments.index'))->assertSee('₱450.00')->assertDontSee('₱400.00');
    }

    public function test_failed_acceptance_notification_rolls_back_payment_creation(): void
    {
        $r = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        Event::listen(NotificationSent::class, fn () => throw new \RuntimeException('notice failed'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson(route('reservations.accept', $r), ['total_payment' => 400, 'payment_confirmed' => 1]);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('notice failed', $e->getMessage());
        }
        $this->assertSame('pending', $r->fresh()->status);
        $this->assertNull($r->fresh()->total);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_reschedule_cancellation_and_official_use_conflict_resolution_preserve_paid_record(): void
    {
        foreach ([false, true] as $official) {
            foreach (['reschedule', 'cancel'] as $action) {
                $payment = $this->payment(['reservation_date' => '2026-10-'.($official ? ($action === 'reschedule' ? '14' : '15') : '12')], ['payment_status' => 'paid']);
                $r = $payment->reservation;
                $before = $payment->fresh()->toArray();
                $use = null;
                if ($official) {
                    $useResponse = $this->postJson(route('official-uses.store'), ['facility_id' => $this->court->id, 'date' => $r->reservation_date, 'start_time' => '21:00', 'end_time' => '03:00', 'purpose' => 'Official Program'])->assertCreated();
                    $use = OfficialUse::find($useResponse->json('id'));
                }
                $data = $action === 'reschedule' ? ['action' => 'reschedule', 'reservation_date' => '2026-10-'.($official ? '18' : '16'), 'start_time' => '13:00', 'end_time' => '14:00'] : ['action' => 'cancel', 'cancellation_confirmed' => 1, 'cancellation_reason' => $official ? 'Official Use' : 'User Requested Cancellation'];
                $this->patchJson(route('reservations.edit-accepted', $r), $data)->assertOk();
                $this->assertSame($before, $payment->fresh()->toArray());
                if ($use) {
                    $this->assertSame('active', $use->fresh()->status);
                    $this->assertSame('resolved', $r->officialUseConflicts()->first()->resolution);
                }
            }
        }
        $this->assertDatabaseCount('payments', 4);
        $this->assertSame(4, Payment::where('payment_status', 'paid')->count());
    }

    public function test_official_use_never_creates_payment_or_enters_reports(): void
    {
        $this->postJson(route('official-uses.store'), ['facility_id' => $this->court->id, 'date' => '2026-10-10', 'start_time' => '13:00', 'end_time' => '17:00', 'purpose' => 'Government Meeting'])->assertCreated();
        $this->assertDatabaseCount('payments', 0);
        $this->get(route('payments.index'))->assertDontSee('Government Meeting')->assertViewHas('summary', fn ($s) => $s['count'] === 0);
        $csv = $this->export('csv');
        $this->assertStringNotContainsString('Government Meeting', $csv);
    }

    public function test_exports_share_all_filters_export_every_match_and_have_current_total_and_status(): void
    {
        $ids = [];
        foreach (range(1, 12) as $index) {
            $ids[] = $this->payment(['total_payment' => '100.25'])->id;
        }
        $this->payment([], ['payment_status' => 'refunded']);
        $this->payment([], ['created_at' => '2026-09-01 12:00:00']);
        $this->payment(['facility_id' => $this->foreignCourt->id]);
        $filters = ['search' => 'Covered Court', 'status' => 'paid', 'from_date' => '2026-10-01', 'to_date' => '2026-10-01', 'per_page' => 10, 'page' => 2, 'sort' => 'id', 'direction' => 'asc'];
        $csv = $this->export('csv', $filters);
        $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", trim(substr($csv, 3))));
        $this->assertSame(PaymentReport::COLUMNS, array_map('trim', $lines[0]));
        $this->assertCount(13, $lines);
        $this->assertSame($ids, array_map(fn ($row) => (int) $row[0], array_slice($lines, 1)));
        $this->assertSame('100.25', $lines[1][5]);
        $this->assertStringNotContainsString('Actions', $csv);
        $this->assertStringNotContainsString('Amount', $csv);
        $book = $this->spreadsheet($this->export('xlsx', $filters));
        $sheet = $book->getSheetByName('Payments');
        $this->assertSame(PaymentReport::COLUMNS, $sheet->rangeToArray('A1:G1')[0]);
        $this->assertSame(13, $sheet->getHighestRow());
        $this->assertSame(100.25, $sheet->getCell('F2')->getValue());
        $this->assertSame('n', $sheet->getCell('F2')->getDataType());
        $this->assertSame(12, $book->getSheetByName('Report')->getCell('B8')->getValue());
        $this->assertSame(1203.0, $book->getSheetByName('Report')->getCell('B9')->getValue());
        $pdf = $this->export('pdf', $filters);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $pdfText = $this->pdfText($pdf);
        foreach (PaymentReport::COLUMNS as $column) {
            $this->assertStringContainsString($column, $pdfText);
        }
        $this->assertSame(12, substr_count($pdfText, 'Juan Dela Cruz'));
        $this->assertStringContainsString('₱1,203.00', $pdfText);
        $this->assertStringNotContainsString('Secret Court', $pdfText);
        $this->assertStringNotContainsString('Amount', $pdfText);
        $this->assertStringNotContainsString('Actions', $pdfText);
        $request = Request::create('/payments/export', 'GET', $filters);
        $request->setUserResolver(fn () => $this->admin);
        $query = PaymentQuery::forRequest($request);
        $report = view('payments.report', ['rows' => $query->get()->map(fn ($p) => PaymentReport::row($p)), 'summary' => PaymentQuery::summary($query), 'metadata' => PaymentReport::metadata($request)])->render();
        $this->assertStringContainsString('₱1,203.00', $report);
        $this->assertStringContainsString('Taft', $report);
        $this->assertStringNotContainsString('Secret Court', $report);
        $this->assertStringNotContainsString('Amount', $report);
        $this->assertStringNotContainsString('Actions', $report);
        $this->assertSame(12, preg_match_all('/<td\b[^>]*>Paid<\/td>/', $report));
    }

    public function test_edits_update_future_exports_and_status_summaries_for_all_formats(): void
    {
        $payment = $this->payment();
        $this->edit($payment, 'refunded')->assertOk();
        $this->get(route('payments.index', ['status' => 'paid']))->assertViewHas('summary', fn ($s) => $s['count'] === 0);
        $this->get(route('payments.index', ['status' => 'refunded']))->assertViewHas('summary', fn ($s) => $s['count'] === 1 && $s['total'] === '1250.25');
        $this->assertStringContainsString('Refunded', $this->export('csv', ['status' => 'refunded']));
        $book = $this->spreadsheet($this->export('xlsx', ['status' => 'refunded']));
        $this->assertSame('Refunded', $book->getSheetByName('Payments')->getCell('G2')->getValue());
        $this->assertStringContainsString('Refunded', $this->pdfText($this->export('pdf', ['status' => 'refunded'])));
    }

    public function test_empty_and_unrecorded_reports_are_valid_and_no_unknown_total_is_invented(): void
    {
        $this->assertSame(PaymentReport::COLUMNS, str_getcsv(trim(substr($this->export('csv'), 3)), ',', '"', ''));
        $book = $this->spreadsheet($this->export('xlsx'));
        $this->assertSame(1, $book->getSheetByName('Payments')->getHighestRow());
        $this->assertSame(0, $book->getSheetByName('Report')->getCell('B8')->getValue());
        $this->assertStringStartsWith('%PDF-', $this->export('pdf'));
        $this->payment(['total_payment' => null]);
        $this->get(route('payments.index'))->assertSee('Not recorded')->assertViewHas('summary', fn ($s) => $s['total'] === '0.00' && $s['unrecorded'] === 1);
        $this->assertNull($this->spreadsheet($this->export('xlsx'))->getSheetByName('Payments')->getCell('F2')->getValue());
    }

    public function test_exports_treat_user_text_as_text_and_escape_report_html(): void
    {
        $this->resident->update(['name' => '=HYPERLINK("evil")']);
        $this->court->update(['name' => '<script>alert(1)</script>']);
        $this->payment();
        $csv = $this->export('csv');
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $book = $this->spreadsheet($this->export('xlsx'));
        $this->assertSame('s', $book->getSheetByName('Payments')->getCell('C2')->getDataType());
        $this->assertSame('=HYPERLINK("evil")', $book->getSheetByName('Payments')->getCell('C2')->getValue());
        $this->get(route('payments.index'))->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_migration_preserves_existing_payment_history_backfills_only_confirmed_acceptance_and_is_idempotent(): void
    {
        $existing = $this->payment();
        $before = $existing->fresh()->toArray();
        $accepted = $this->reservation(['total_payment' => '0.00']);
        $this->reservation(['status' => 'pending']);
        $this->reservation(['status' => 'cancelled']);
        $this->reservation(['total_payment' => null]);
        $migration = require database_path('migrations/2026_10_02_000004_integrate_payment_records.php');
        $migration->up();
        $migration->up();
        $this->assertSame($before, $existing->fresh()->toArray());
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('paid', $accepted->payment->payment_status);
        $this->assertSame('0.00', $accepted->payment->amount);
        $migration->down();
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_large_csv_crosses_query_chunks_pdf_has_repeating_headers_and_page_queries_do_not_grow_per_row(): void
    {
        foreach (range(1, 502) as $index) {
            $this->payment(['total_payment' => '1.01'], ['created_at' => $index <= 80 ? '2026-10-01 12:00:00' : '2026-10-02 12:00:00']);
        }
        $csv = $this->export('csv');
        $lines = explode("\n", trim(substr($csv, 3)));
        $this->assertCount(503, $lines);
        $ids = array_map(fn ($line) => (int) str_getcsv($line, ',', '"', '')[0], array_slice($lines, 1));
        $this->assertCount(502, array_unique($ids));
        $this->assertContains(1, $ids);
        $this->assertContains(502, $ids);
        $pdf = $this->export('pdf', ['to_date' => '2026-10-01']);
        $text = $this->pdfText($pdf);
        $this->assertGreaterThan(1, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
        $this->assertGreaterThan(1, substr_count($text, 'Payment ID'));
        $this->assertSame(80, substr_count($text, 'Juan Dela Cruz'));
        $this->assertStringContainsString('₱80.80', $text);
        $counts = [];
        foreach ([10, 100] as $size) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->get(route('payments.index', ['per_page' => $size]))->assertOk();
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertLessThanOrEqual($counts[0] + 1, $counts[1]);
        $this->assertLessThan(15, $counts[1]);
    }

    public function test_editing_last_filtered_page_repositions_to_a_valid_page_and_updates_exports(): void
    {
        foreach (range(1, 11) as $i) {
            $this->payment();
        }
        $last = Payment::first(); // Default sort is descending; ID 1 is alone on page 2.
        $this->edit($last, 'refunded')->assertOk();
        $this->get(route('payments.index', ['status' => 'paid', 'page' => 2]))
            ->assertViewHas('payments', fn ($rows) => $rows->currentPage() === 1 && $rows->count() === 10)
            ->assertViewHas('summary', fn ($summary) => $summary['count'] === 10 && $summary['total'] === '12502.50');
        $csv = $this->export('csv', ['status' => 'paid']);
        $this->assertStringNotContainsString('Refunded', $csv);
        $this->assertCount(11, explode("\n", trim(substr($csv, 3))));
    }
}

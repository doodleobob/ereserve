<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Support\PaymentReport;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SuperAdminPaymentOversightTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $admin;

    private User $resident;

    private Facility $localFacility;

    private Facility $foreignFacility;

    private Payment $local;

    private Payment $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        Mail::fake();
        Queue::fake();
        $this->super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->resident = User::factory()->create(['role' => 'user', 'barangay' => 'Washington', 'name' => 'Oversight Resident']);
        foreach (['localFacility' => 'Taft', 'foreignFacility' => 'Washington'] as $field => $barangay) {
            $this->{$field} = Facility::create(['barangay' => $barangay, 'slug' => $field, 'name' => $field, 'category' => 'Facility', 'description' => 'Court', 'capacity' => 20, 'location' => $barangay, 'status' => 'Available', 'hourly_rate' => 100]);
        }
        $this->local = $this->payment($this->localFacility);
        $this->foreign = $this->payment($this->foreignFacility, ['status' => 'cancelled'], ['payment_status' => 'paid']);
        $this->actingAs($this->super);
    }

    private function payment(Facility $facility, array $booking = [], array $data = []): Payment
    {
        $reservation = Reservation::create([
            'user_id' => $this->resident->id, 'facility_id' => $facility->id,
            // Deliberately disagree with the resource: ownership follows the resource.
            'barangay' => 'San Juan', 'facility_slug' => $facility->slug, 'facility_name' => 'Legacy Name',
            'category' => 'Facility', 'location' => $facility->barangay, 'reservation_date' => '2026-11-10',
            'start_time' => '09:00', 'end_time' => '10:00', 'purpose' => 'Meeting', 'attendees' => 5,
            'status' => 'accepted', 'total_payment' => '1250.25', 'hourly_rate_snapshot' => 100, ...$booking,
        ]);
        $payment = $reservation->payment()->create(['amount' => '7.00', 'payment_status' => 'pending', ...$data]);
        $payment->forceFill(['created_at' => $data['created_at'] ?? '2026-10-01 12:00:00'])->save();

        return $payment;
    }

    private function export(string $format, array $filters = []): string
    {
        return $this->get(route('payments.export', ['format' => $format, ...$filters]))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
    }

    private function csvRows(string $bytes): array
    {
        return array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", trim(substr($bytes, 3))));
    }

    private function spreadsheet(string $bytes)
    {
        $path = tempnam(sys_get_temp_dir(), 'oversight-xlsx-');
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

    public function test_super_admin_sees_cross_barangay_payments_and_read_only_details_with_reservation_totals(): void
    {
        $response = $this->get(route('payments.index'))->assertOk()->assertSee('read-only')
            ->assertSee('localFacility')->assertSee('foreignFacility')->assertSee('Barangay')
            ->assertSee('Taft')->assertSee('Washington')->assertSee('1,250.25')
            ->assertSee('payment-view-'.$this->foreign->id)->assertDontSee('payment-edit-', false)
            ->assertDontSee('Save Changes')->assertDontSee('data-payment-edit', false)
            ->assertViewHas('canEditPayments', false);
        $this->assertEqualsCanonicalizing([$this->local->id, $this->foreign->id], $response->viewData('payments')->modelKeys());
        $this->assertSame(['count' => 2, 'total' => '2500.50', 'unrecorded' => 0], $response->viewData('summary'));
        $this->assertStringContainsString('Close', $response->getContent());
    }

    public function test_super_admin_cannot_mutate_any_payment_status_using_json_or_spoofed_forms(): void
    {
        foreach ([$this->local, $this->foreign] as $payment) {
            $before = $payment->fresh()->getAttributes();
            $reservationBefore = $payment->reservation->getAttributes();
            foreach (Payment::STATUSES as $status) {
                $payload = ['payment_status' => $status, 'receipt_confirmed' => 1, 'amount' => 999, 'total_payment' => 999, 'barangay_id' => 999];
                $this->patchJson(route('payments.update', $payment), $payload)->assertForbidden();
                $this->post(route('payments.update', $payment), ['_method' => 'PATCH', ...$payload])->assertForbidden();
            }
            $this->patchJson(route('payments.update', $payment), [])->assertForbidden();
            $this->assertSame($before, $payment->fresh()->getAttributes());
            $this->assertSame($reservationBefore, $payment->reservation->fresh()->getAttributes());
        }
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_super_admin_cannot_correct_reservation_payment_total_through_alternate_endpoint(): void
    {
        foreach ([$this->local, $this->foreign] as $payment) {
            $this->patchJson(route('reservations.payment', $payment->reservation), ['total_payment' => 999])->assertForbidden();
            $this->patchJson(route('reservations.payment', $payment->reservation), [])->assertForbidden();
            $this->assertSame('1250.25', $payment->reservation->fresh()->total);
        }
        $this->actingAs($this->admin)->patchJson(route('reservations.payment', $this->local->reservation), ['total_payment' => '1500.00'])->assertOk();
        $this->assertSame('1500.00', $this->local->reservation->fresh()->total);
        $this->patchJson(route('reservations.payment', $this->foreign->reservation), ['total_payment' => 999])->assertForbidden();
    }

    public function test_barangay_admin_retains_view_and_edit_but_forged_filters_and_foreign_payment_ids_cannot_escape_scope(): void
    {
        $this->actingAs($this->admin)->get(route('payments.index', ['barangay' => 'Washington', 'barangay_id' => 999]))
            ->assertOk()->assertSee('payment-edit-'.$this->local->id)->assertDontSee('foreignFacility')
            ->assertViewHas('payments', fn ($rows) => $rows->modelKeys() === [$this->local->id]);
        $this->patchJson(route('payments.update', $this->local), ['payment_status' => 'paid', 'receipt_confirmed' => 1])->assertOk();
        $this->assertSame('paid', $this->local->fresh()->payment_status);
        $this->patchJson(route('payments.update', $this->foreign), ['payment_status' => 'refunded', 'receipt_confirmed' => 1, 'barangay' => 'Taft'])->assertForbidden();
        $this->assertSame('paid', $this->foreign->fresh()->payment_status);
    }

    public function test_super_admin_filters_use_resource_barangay_with_legacy_reservation_fallback(): void
    {
        $legacy = $this->payment($this->localFacility, ['facility_id' => null, 'barangay' => 'Washington']);
        $this->get(route('payments.index', ['barangay' => 'Taft']))->assertViewHas('payments', fn ($rows) => $rows->modelKeys() === [$this->local->id]);
        $this->get(route('payments.index', ['barangay' => 'Washington']))->assertViewHas('payments', fn ($rows) => $rows->modelKeys() === [$legacy->id, $this->foreign->id]);
        $this->get(route('payments.index', ['barangay' => 'San Juan']))->assertViewHas('payments', fn ($rows) => $rows->isEmpty());
        $this->get(route('payments.index', ['sort' => 'barangay', 'direction' => 'asc']))->assertViewHas('payments', fn ($rows) => $rows->first()->id === $this->local->id);
    }

    public function test_super_admin_search_status_and_recorded_date_filters_combine(): void
    {
        foreach (['PAY-'.$this->foreign->id, 'RES-'.$this->foreign->reservation_id, 'foreignFacility'] as $search) {
            $this->get(route('payments.index', ['search' => $search]))->assertViewHas('payments', fn ($rows) => $rows->modelKeys() === [$this->foreign->id]);
        }
        $this->get(route('payments.index', ['search' => 'Oversight Resident']))->assertViewHas('payments', fn ($rows) => $rows->total() === 2);
        foreach (Payment::STATUSES as $status) {
            $this->get(route('payments.index', ['status' => $status]))->assertViewHas('payments', fn ($rows) => $rows->every(fn ($payment) => $payment->payment_status === $status));
        }
        $this->get(route('payments.index', ['search' => 'Oversight', 'barangay' => 'Washington', 'status' => 'paid', 'from_date' => '2026-10-01', 'to_date' => '2026-10-01']))
            ->assertOk()->assertViewHas('payments', fn ($rows) => $rows->modelKeys() === [$this->foreign->id]);
        $this->get(route('payments.index', ['from_date' => '2026-10-02']))->assertViewHas('payments', fn ($rows) => $rows->isEmpty());
        $this->get(route('payments.index', ['to_date' => '2026-09-30']))->assertViewHas('payments', fn ($rows) => $rows->isEmpty());
        $this->getJson(route('payments.index', ['barangay' => "' OR 1=1 --"]))->assertUnprocessable();
        $this->getJson(route('payments.index', ['sort' => 'password']))->assertUnprocessable();
    }

    public function test_all_three_super_admin_exports_share_filters_include_barangay_and_use_reservation_total(): void
    {
        $filters = ['search' => 'Oversight', 'barangay' => 'Washington', 'status' => 'paid', 'from_date' => '2026-10-01', 'to_date' => '2026-10-01'];
        $rows = $this->csvRows($this->export('csv', $filters));
        $this->assertSame(PaymentReport::columns(true), $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $this->foreign->id, $rows[1][0]);
        $this->assertSame('Washington', $rows[1][2]);
        $this->assertSame('1250.25', $rows[1][6]);
        $this->assertNotContains('Actions', $rows[0]);
        $book = $this->spreadsheet($this->export('xlsx', $filters));
        $sheet = $book->getSheetByName('Payments');
        $this->assertSame(2, $sheet->getHighestRow());
        $this->assertSame('Barangay', $sheet->getCell('C1')->getValue());
        $this->assertSame('Washington', $sheet->getCell('C2')->getValue());
        $this->assertSame(1250.25, $sheet->getCell('G2')->getValue());
        $this->assertSame('n', $sheet->getCell('G2')->getDataType());
        $this->assertSame(1, $book->getSheetByName('Report')->getCell('B8')->getValue());
        $book->disconnectWorksheets();
        $pdf = $this->export('pdf', $filters);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $text = $this->pdfText($pdf);
        $this->assertStringContainsString('Barangay', $text);
        $this->assertStringContainsString('foreignFacility', $text);
        $this->assertStringContainsString('1,250.25', $text);
        $this->assertStringNotContainsString('localFacility', $text);
        $this->assertStringNotContainsString('Actions', $text);
    }

    public function test_super_admin_exports_include_all_matching_rows_beyond_current_page(): void
    {
        for ($index = 0; $index < 11; $index++) {
            $this->payment($this->foreignFacility, [], ['payment_status' => 'paid']);
        }
        $filters = ['barangay' => 'Washington', 'status' => 'paid', 'page' => 2, 'per_page' => 10];
        $this->get(route('payments.index', $filters))->assertViewHas('payments', fn ($rows) => $rows->count() === 2 && $rows->total() === 12);
        $this->assertCount(13, $this->csvRows($this->export('csv', $filters)));
        $book = $this->spreadsheet($this->export('xlsx', $filters));
        $this->assertSame(13, $book->getSheetByName('Payments')->getHighestRow());
        $book->disconnectWorksheets();
        $text = $this->pdfText($this->export('pdf', $filters));
        $this->assertSame(12, substr_count($text, 'foreignFacility'));
    }

    public function test_admin_exports_cannot_bypass_tenant_scope_in_any_format(): void
    {
        $this->actingAs($this->admin);
        $filters = ['barangay' => 'Washington', 'barangay_id' => 999];
        $rows = $this->csvRows($this->export('csv', $filters));
        $this->assertSame(PaymentReport::COLUMNS, $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $this->local->id, $rows[1][0]);
        $book = $this->spreadsheet($this->export('xlsx', $filters));
        $this->assertSame($this->local->id, (int) $book->getSheetByName('Payments')->getCell('A2')->getValue());
        $this->assertSame(2, $book->getSheetByName('Payments')->getHighestRow());
        $book->disconnectWorksheets();
        $text = $this->pdfText($this->export('pdf', $filters));
        $this->assertStringContainsString('localFacility', $text);
        $this->assertStringNotContainsString('foreignFacility', $text);
    }

    public function test_cancelled_reservation_and_paid_payment_stay_independent_during_oversight(): void
    {
        $this->get(route('payments.index', ['status' => 'paid']))->assertOk()->assertSee('Paid');
        $this->export('csv', ['status' => 'paid']);
        $this->assertSame('cancelled', $this->foreign->reservation->fresh()->status);
        $this->assertSame('paid', $this->foreign->fresh()->payment_status);
        $this->assertNull($this->foreign->fresh()->refunded_at);
    }

    public function test_oversight_reads_and_exports_do_not_write_or_send_notifications_mail_or_jobs(): void
    {
        $beforePayments = Payment::orderBy('id')->get()->map->getAttributes()->all();
        $beforeReservations = Reservation::orderBy('id')->get()->map->getAttributes()->all();
        $this->get(route('payments.index'))->assertOk();
        $this->get(route('payments.index', ['search' => 'Oversight', 'barangay' => 'Washington', 'status' => 'paid']))->assertOk();
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->export($format);
        }
        $this->assertSame($beforePayments, Payment::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($beforeReservations, Reservation::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(0, DB::table('notifications')->count());
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_residents_and_guests_cannot_access_payment_management_or_exports(): void
    {
        $this->actingAs($this->resident)->get(route('payments.index'))->assertForbidden();
        foreach (['pdf', 'xlsx', 'csv'] as $format) {
            $this->get(route('payments.export', ['format' => $format]))->assertForbidden();
        }
        $this->patchJson(route('payments.update', $this->foreign), ['payment_status' => 'refunded'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get(route('payments.index'))->assertRedirect(route('login'));
    }
}

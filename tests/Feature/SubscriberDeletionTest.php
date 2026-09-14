<?php

namespace Tests\Feature;

use App\Admin\Tables\SubscribersTable;
use App\Livewire\Admin\DataTable;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A subscriber who exists in the ledger cannot be deleted — the history IS the money (specs/RISKS.md §5).
 *
 * Deleting one cascades: the database takes his readings, invoices, receipts and their allocations
 * with him and nothing records that they existed, while the receipt and invoice numbering keeps a
 * permanent hole. The status is the way out, and «موقوف» already behaves as a working archive: out
 * of the round, the readings and the billing, still collectable and still on file.
 *
 * A row with NO ledger trace is a different act: a duplicate, or a row somebody created to try the
 * form. Deleting that is honest correction, so it stays possible — and it is recorded.
 */
class SubscriberDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Route $route;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'مدير', 'email' => 'admin@delete.test', 'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'Ain El Remmaneh']);
    }

    private function subscriber(string $name, string $meterNo): Subscriber
    {
        return Subscriber::create([
            'route_id' => $this->route->id, 'name' => $name, 'meter_no' => $meterNo,
            'type' => Subscriber::TYPE_METERED, 'maintenance_mode' => Subscriber::MAINT_EXEMPT,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
    }

    private function bill(Subscriber $subscriber): Bill
    {
        return Bill::create([
            'number' => 'INV-2026-08-'.$subscriber->id, 'subscriber_id' => $subscriber->id,
            'period' => '2026-08', 'type' => Subscriber::TYPE_METERED, 'total' => 25.0,
        ]);
    }

    // ---- the three traces, one test each: any one of them is enough -------------------

    public function test_a_subscriber_with_an_invoice_is_not_deletable(): void
    {
        $subscriber = $this->subscriber('عليه فاتورة', 'M-1');
        $this->bill($subscriber);

        $this->assertFalse((new SubscribersTable)->canDelete($subscriber));
    }

    public function test_a_subscriber_with_only_a_receipt_is_not_deletable(): void
    {
        $subscriber = $this->subscriber('عليه وصل', 'M-2');
        Payment::create([
            'number' => 'REC-2026-08-9001', 'subscriber_id' => $subscriber->id,
            'amount' => 10.0, 'method' => Payment::METHOD_CASH_USD,
        ]);

        $this->assertFalse((new SubscribersTable)->canDelete($subscriber));
    }

    public function test_a_subscriber_with_only_a_reading_is_not_deletable(): void
    {
        $subscriber = $this->subscriber('عليه قراءة', 'M-3');
        Reading::create([
            'subscriber_id' => $subscriber->id, 'period' => '2026-08',
            'current_reading' => 100, 'previous_reading' => 0, 'consumption' => 100,
        ]);

        $this->assertFalse((new SubscribersTable)->canDelete($subscriber));
    }

    // ---- the refusal is visible, names the way out, and destroys nothing --------------

    public function test_the_refusal_names_the_alternative_and_nothing_is_destroyed(): void
    {
        $subscriber = $this->subscriber('محمد عيتاني', 'M-4');
        $bill = $this->bill($subscriber);

        $component = Livewire::actingAs($this->admin)
            ->test(DataTable::class, ['table' => SubscribersTable::class])
            ->call('deleteRecord', $subscriber->id);

        $this->assertSame(__('a.del_sub_refused'), $component->get('flash'));
        $this->assertStringContainsString('موقوف', (string) $component->get('flash'));

        // nothing left the database
        $this->assertDatabaseHas('subscribers', ['id' => $subscriber->id]);
        $this->assertDatabaseHas('bills', ['id' => $bill->id]);
        $this->assertSame(0, AuditLog::where('action', 'subscriber.deleted')->count());
    }

    // ---- a row that never existed in the ledger stays deletable — and is recorded -----

    public function test_a_subscriber_with_no_ledger_trace_is_deleted_and_recorded(): void
    {
        $subscriber = $this->subscriber('اسم مكرّر بالغلط', 'M-5');

        $this->assertTrue((new SubscribersTable)->canDelete($subscriber));
        $this->assertNull((new SubscribersTable)->deleteRefusal($subscriber));

        Livewire::actingAs($this->admin)
            ->test(DataTable::class, ['table' => SubscribersTable::class])
            ->call('deleteRecord', $subscriber->id);

        $this->assertDatabaseMissing('subscribers', ['id' => $subscriber->id]);

        $log = AuditLog::where('action', 'subscriber.deleted')->latest('id')->first();
        $this->assertNotNull($log, 'even a history-free deletion is recorded');
        $this->assertSame('اسم مكرّر بالغلط', $log->payload['name']);
        $this->assertSame('M-5', $log->payload['meter_no']);
        $this->assertSame('Ain El Remmaneh', $log->payload['route']);
    }
}

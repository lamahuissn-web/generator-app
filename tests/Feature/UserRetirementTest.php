<?php

namespace Tests\Feature;

use App\Admin\Forms\UserForm;
use App\Admin\Tables\UsersTable;
use App\Livewire\Admin\DataTable;
use App\Livewire\Admin\ResourceForm;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\CollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A user is retired, never deleted (specs/RISKS.md §5) — the same rule the subscriber list now
 * follows, for the same reason: the ledger's rows name people, and those columns are
 * `nullOnDelete`, so deleting an account does not fail. It quietly strips the name off the receipts
 * that person collected and leaves the office with amounts it cannot attribute to anybody.
 *
 * An account therefore gets a status. `inactive` cannot sign in and is stopped at the next request
 * even if it is already signed in — a phone in a van is exactly the account an office retires in a
 * hurry — while everything that account did keeps its name on it.
 */
class UserRetirementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'مدير', 'email' => 'admin@retire.test', 'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
    }

    private function user(string $name, string $role, string $status): User
    {
        return User::create([
            'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@retire.test',
            'password' => bcrypt('secret'), 'role' => $role, 'status' => $status, 'locale' => 'ar',
        ]);
    }

    // ---- the door is closed ---------------------------------------------------------

    public function test_a_disabled_admin_cannot_sign_in_to_the_panel(): void
    {
        $retired = $this->user('مدير سابق', User::ROLE_ADMIN, User::STATUS_INACTIVE);

        $this->post('/admin/login', ['identity' => $retired->email, 'password' => 'secret'])
            ->assertSessionHasErrors('identity');

        $this->assertGuest();
    }

    public function test_a_disabled_collector_cannot_sign_in_to_the_field_app(): void
    {
        $retired = $this->user('جابي سابق', User::ROLE_COLLECTOR, User::STATUS_INACTIVE);

        $this->from('/collector/login')
            ->post('/collector/login', ['identity' => $retired->email, 'password' => 'secret'])
            ->assertSessionHasErrors('identity');

        $this->assertGuest();
    }

    // ---- and it closes mid-session too ---------------------------------------------

    public function test_a_disabled_account_is_stopped_on_the_next_request(): void
    {
        $retired = $this->user('مدير سابق', User::ROLE_ADMIN, User::STATUS_INACTIVE);

        $this->actingAs($retired)->get('/admin')->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_a_disabled_collector_is_stopped_in_the_field_app_too(): void
    {
        $retired = $this->user('جابي سابق', User::ROLE_COLLECTOR, User::STATUS_INACTIVE);

        $this->actingAs($retired)->get(route('collector.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_active_account_is_untouched(): void
    {
        $this->post('/admin/login', ['identity' => $this->admin->email, 'password' => 'secret']);
        $this->assertAuthenticatedAs($this->admin);

        $this->get('/admin')->assertOk();
    }

    // ---- the name stays on the money ------------------------------------------------

    public function test_deleting_a_user_who_names_money_is_refused_and_his_name_survives(): void
    {
        $collector = $this->user('Jad Haddad', User::ROLE_COLLECTOR, User::STATUS_ACTIVE);
        $subscriber = Subscriber::create([
            'name' => 'محمد عيتاني', 'meter_no' => 'M-1', 'type' => Subscriber::TYPE_METERED,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
        $payment = Payment::create([
            'number' => 'REC-2026-09-9001', 'subscriber_id' => $subscriber->id,
            'collector_id' => $collector->id, 'amount' => 40.0, 'method' => Payment::METHOD_CASH_USD,
        ]);

        $table = new UsersTable;
        $this->assertFalse($table->canDelete($collector));
        $this->assertSame(__('a.del_user_refused'), $table->deleteRefusal($collector));
        $this->assertStringContainsString('عطّل', (string) $table->deleteRefusal($collector));

        $component = Livewire::actingAs($this->admin)
            ->test(DataTable::class, ['table' => UsersTable::class])
            ->call('deleteRecord', $collector->id);

        $this->assertSame(__('a.del_user_refused'), $component->get('flash'));

        // nothing was destroyed, and the receipt still says who collected it
        $this->assertDatabaseHas('users', ['id' => $collector->id]);
        $this->assertSame('Jad Haddad', $payment->fresh()->collector->name);

        // and the collection screen still knows the money was his
        $rows = CollectionService::byCollector(now()->startOfMonth(), now());
        $this->assertTrue($rows->contains(fn ($row) => (int) $row['collector_id'] === $collector->id));
    }

    public function test_a_user_who_names_nothing_is_deleted_and_recorded(): void
    {
        $ghost = $this->user('حساب غلط', User::ROLE_COLLECTOR, User::STATUS_ACTIVE);

        $this->assertTrue((new UsersTable)->canDelete($ghost));
        $this->assertNull((new UsersTable)->deleteRefusal($ghost));

        Livewire::actingAs($this->admin)
            ->test(DataTable::class, ['table' => UsersTable::class])
            ->call('deleteRecord', $ghost->id);

        $this->assertDatabaseMissing('users', ['id' => $ghost->id]);

        $log = AuditLog::where('action', 'user.deleted')->latest('id')->first();
        $this->assertNotNull($log, 'even a footprint-free deletion is recorded');
        $this->assertSame('حساب غلط', $log->payload['name']);
    }

    public function test_a_user_cannot_delete_themselves(): void
    {
        $self = $this->user('مدير سابق', User::ROLE_ADMIN, User::STATUS_ACTIVE);

        $this->actingAs($self);
        $table = new UsersTable;

        $this->assertFalse($table->canDelete($self));
        $this->assertSame(__('a.del_user_self'), $table->deleteRefusal($self));
    }

    // ---- the office's way of retiring somebody --------------------------------------

    public function test_the_form_can_retire_an_account(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => UserForm::class])
            ->set('state.name', 'جابي جديد')
            ->set('state.email', 'new@retire.test')
            ->set('state.role', User::ROLE_COLLECTOR)
            ->set('state.status', User::STATUS_INACTIVE)
            ->set('state.password', 'password123')
            ->call('save')
            ->assertHasNoErrors();

        $created = User::where('email', 'new@retire.test')->firstOrFail();

        $this->assertTrue($created->isInactive());
        $this->assertFalse((bool) $created->isActive());
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Forms\UserForm;
use App\Livewire\Admin\ResourceForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Signing in with a name instead of an address.
 *
 * The office asked for it plainly: «بدي سهل دخول على البرنامج بدون كتابة الايميل بس استخدم الاسم
 * اليوزر سواء للادمن او لل محصل». An address is long, has an @ in it, and on a phone keyboard it
 * collects invisible characters; a username is short and is what the office and the collectors
 * actually call each other.
 *
 * The address is not thrown away: it stays a valid way in, and every row that already existed was
 * given a name derived from its own local part — so nobody's sign-in changed meaning, they simply
 * gained a shorter one. Three rules are pinned here, and they are the whole feature:
 *
 *   1. one field, read once (`User::credentialFor()`): an address if it is shaped like one,
 *      otherwise a username, with the invisible characters gone in either case;
 *   2. a name is derived when nobody chose one, and never renamed behind the office's back;
 *   3. the doors still know their own people: a collector cannot reach the panel by username,
 *      an admin cannot reach the collector portal, and a retired account is refused by name.
 */
class UsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email, string $role, ?string $username = null, string $status = User::STATUS_ACTIVE): User
    {
        return User::create([
            'name' => 'مستخدم', 'username' => $username, 'email' => $email,
            'password' => bcrypt('secret'), 'role' => $role, 'status' => $status, 'locale' => 'ar',
        ]);
    }

    // ---- the door, with a name ----------------------------------------------------------

    public function test_a_username_signs_in_at_the_panel(): void
    {
        $this->user('admin@example.com', User::ROLE_ADMIN);

        $this->post('/admin/login', ['identity' => 'admin', 'password' => 'secret'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_a_username_signs_in_at_the_collector_door(): void
    {
        $this->user('collector1@example.com', User::ROLE_COLLECTOR);

        $this->post(route('collector.login.attempt'), ['identity' => 'collector1', 'password' => 'secret'])
            ->assertRedirect(route('collector.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_the_address_still_opens_both_doors(): void
    {
        $this->user('admin@example.com', User::ROLE_ADMIN);
        $this->user('collector1@example.com', User::ROLE_COLLECTOR);

        $this->post('/admin/login', ['identity' => 'admin@example.com', 'password' => 'secret'])
            ->assertRedirect(route('admin.dashboard'));

        auth()->logout();

        $this->post(route('collector.login.attempt'), ['identity' => 'Collector1@Example.com', 'password' => 'secret'])
            ->assertRedirect(route('collector.dashboard'));
    }

    public function test_the_wrong_password_is_still_refused(): void
    {
        $this->user('admin@example.com', User::ROLE_ADMIN);

        $this->post('/admin/login', ['identity' => 'admin', 'password' => 'wrong'])
            ->assertSessionHasErrors('identity');

        $this->assertGuest();
    }

    /**
     * A name nobody answers to is refused in the same words as a wrong password, so the door does
     * not become a way to ask the program which usernames exist.
     */
    public function test_an_unknown_username_is_refused_like_a_wrong_password(): void
    {
        $this->user('admin@example.com', User::ROLE_ADMIN);

        $this->post('/admin/login', ['identity' => 'someone-else', 'password' => 'secret']);
        $unknown = session('errors')->first('identity');

        $this->post('/admin/login', ['identity' => 'admin', 'password' => 'nope']);
        $wrong = session('errors')->first('identity');

        $this->assertNotNull($unknown, 'the unknown name was refused');
        $this->assertSame($unknown, $wrong, 'both refusals read the same, so the door names no accounts');
    }

    // ---- the doors still know their own people -------------------------------------------

    public function test_a_collector_cannot_enter_the_panel_by_username(): void
    {
        $this->user('collector1@example.com', User::ROLE_COLLECTOR);

        $this->post('/admin/login', ['identity' => 'collector1', 'password' => 'secret'])
            ->assertSessionHasErrors('identity');

        $this->assertGuest();
    }

    public function test_an_admin_cannot_enter_the_collector_portal_by_username(): void
    {
        $this->user('admin@example.com', User::ROLE_ADMIN);

        $this->post(route('collector.login.attempt'), ['identity' => 'admin', 'password' => 'secret'])
            ->assertSessionHasErrors('identity');

        $this->assertGuest();
    }

    public function test_a_retired_account_is_refused_by_name_with_its_own_reason(): void
    {
        $this->user('collector1@example.com', User::ROLE_COLLECTOR, null, User::STATUS_INACTIVE);

        $response = $this->post(route('collector.login.attempt'), ['identity' => 'collector1', 'password' => 'secret']);

        $this->assertGuest();
        $this->assertSame(__('auth.account_disabled'), $response->getSession()->get('errors')->first('identity'));
    }

    // ---- a name as a phone keyboard produces it ------------------------------------------

    public function test_an_invisible_character_in_a_username_is_ignored(): void
    {
        $this->user('admin@example.com', User::ROLE_ADMIN);

        $dirty = ["\u{200F}admin", "admin\u{2069}", "\u{FEFF}ADMIN ", " ad\u{200B}min"];

        foreach ($dirty as $variant) {
            $this->post('/admin/login', ['identity' => $variant, 'password' => 'secret'])
                ->assertRedirect(route('admin.dashboard'));

            auth()->logout();
        }
    }

    public function test_an_arabic_username_signs_in(): void
    {
        $this->user('hussein@example.com', User::ROLE_ADMIN, 'حسين');

        $this->post('/admin/login', ['identity' => 'حسين', 'password' => 'secret'])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_cleaner_keeps_letters_digits_and_three_marks_only(): void
    {
        $this->assertSame('admin', clean_username(' Admin '));
        $this->assertSame('hussein.h-1', clean_username('Hussein.H-1'));
        $this->assertSame('حسين٢', clean_username('حسين٢'));
        $this->assertSame('', clean_username('   '));

        // An address typed into the same field must NOT become a username: the @ and everything a
        // username cannot carry are dropped, which is what tells the two apart (see credentialFor).
        $this->assertSame('admin', User::credentialFor('admin')['username'] ?? null);
        $this->assertSame(['email' => 'admin@example.com'], User::credentialFor(' Admin@Example.com '));
        $this->assertNull(User::credentialFor('   '), 'nothing typed is not a name');
    }

    // ---- the name a row is born with ----------------------------------------------------

    public function test_a_user_created_without_a_username_gets_one_from_the_address(): void
    {
        $user = $this->user('collector1@example.com', User::ROLE_COLLECTOR);

        $this->assertSame('collector1', $user->fresh()->username);
    }

    public function test_a_second_user_with_the_same_local_part_gets_the_next_free_name(): void
    {
        $first = $this->user('jad@example.com', User::ROLE_COLLECTOR);
        $second = $this->user('jad@other.com', User::ROLE_COLLECTOR);

        $this->assertSame('jad', $first->fresh()->username);
        $this->assertSame('jad2', $second->fresh()->username, 'two people never answer to one name');
    }

    public function test_a_chosen_username_is_never_renamed(): void
    {
        $user = $this->user('jad@example.com', User::ROLE_COLLECTOR, 'jad-haddad');

        $user->fill(['phone' => '03 111222'])->save();

        $this->assertSame('jad-haddad', $user->fresh()->username);
    }

    // ---- the screen the office uses -----------------------------------------------------

    public function test_the_office_can_type_a_username_on_the_user_screen(): void
    {
        $admin = $this->user('admin@example.com', User::ROLE_ADMIN);

        Livewire::actingAs($admin)
            ->test(ResourceForm::class, ['form' => UserForm::class])
            ->set('state.name', 'حسن مرتضى')
            ->set('state.username', 'Hassan.M')
            ->set('state.email', 'hassan@example.com')
            ->set('state.role', User::ROLE_COLLECTOR)
            ->set('state.status', User::STATUS_ACTIVE)
            ->set('state.password', 'collector123')
            ->call('save')
            ->assertHasNoErrors();

        $created = User::query()->firstWhere('email', 'hassan@example.com');

        $this->assertNotNull($created, 'the screen saved the account');
        $this->assertSame('hassan.m', $created->username, 'the name is stored as it will be typed');
    }

    public function test_a_taken_username_is_refused_on_the_screen_rather_than_renamed(): void
    {
        $admin = $this->user('admin@example.com', User::ROLE_ADMIN);

        Livewire::actingAs($admin)
            ->test(ResourceForm::class, ['form' => UserForm::class])
            ->set('state.name', 'حسن مرتضى')
            ->set('state.username', 'admin')
            ->set('state.email', 'hassan@example.com')
            ->set('state.role', User::ROLE_COLLECTOR)
            ->set('state.status', User::STATUS_ACTIVE)
            ->set('state.password', 'collector123')
            ->call('save')
            ->assertHasErrors('state.username');

        $this->assertNull(
            User::query()->firstWhere('email', 'hassan@example.com'),
            'a refused save writes nothing'
        );
    }

    public function test_leaving_the_username_blank_takes_it_from_the_address(): void
    {
        $admin = $this->user('admin@example.com', User::ROLE_ADMIN);

        Livewire::actingAs($admin)
            ->test(ResourceForm::class, ['form' => UserForm::class])
            ->set('state.name', 'زينب سليمان')
            ->set('state.username', '')
            ->set('state.email', 'Zeinab@Example.com')
            ->set('state.role', User::ROLE_COLLECTOR)
            ->set('state.status', User::STATUS_ACTIVE)
            ->set('state.password', 'collector123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('zeinab', (string) User::query()->firstWhere('email', 'zeinab@example.com')?->username);
    }
}

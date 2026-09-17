<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The address a phone keyboard actually produces.
 *
 * A collector types their address on an Android keyboard and it looks right, but the string holds
 * characters nobody can see: bidi marks and isolates (U+200E/U+200F/U+2066–U+2069), zero-width
 * spaces, a non-breaking space, or the space an autocomplete suggestion leaves behind. The
 * browser's own email check then refuses to submit and blames a character the operator cannot
 * find, and the address never reaches the server at all.
 *
 * These tests pin the two halves of the fix: clean_email() removes exactly the characters that
 * cannot appear in an address (and nothing else), and both doors accept an address carrying them.
 * The sign-in still refuses the wrong password — cleaning is hygiene, not a way in.
 */
class LoginEmailHygieneTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email, string $role, string $status = User::STATUS_ACTIVE): User
    {
        return User::create([
            'name' => 'مستخدم', 'email' => $email, 'password' => bcrypt('secret'),
            'role' => $role, 'status' => $status, 'locale' => 'ar',
        ]);
    }

    // ---- what the cleaner keeps and what it drops -------------------------------------

    public function test_the_cleaner_removes_invisible_characters_a_keyboard_leaves(): void
    {
        $dirty = [
            "collector1@example.com\u{2069}",          // pop directional isolate
            "\u{200F}collector1@example.com",           // right-to-left mark
            "collector1@\u{200E}example.com",           // left-to-right mark
            "collector1@example.com\u{00A0}",           // non-breaking space
            "collector1@ example.com",                  // plain space from autofill
            "collector1@example.com ",                  // trailing space
            "\u{FEFF}collector1@example.com",           // byte-order mark
            "collector1\u{200B}@example.com",           // zero-width space
        ];

        foreach ($dirty as $variant) {
            $this->assertSame(
                'collector1@example.com',
                clean_email($variant),
                'clean_email() must reduce every keyboard variant to the same address'
            );
        }
    }

    public function test_the_cleaner_lowercases_and_survives_null(): void
    {
        $this->assertSame('collector1@example.com', clean_email('Collector1@Example.COM'));
        $this->assertNull(clean_email(null));
    }

    public function test_the_cleaner_does_not_alter_a_legitimate_address(): void
    {
        $address = 'first.last+generator_1@sub.example.co.uk';

        $this->assertSame($address, clean_email($address));
    }

    // ---- the collector door ------------------------------------------------------------

    public function test_a_collector_can_sign_in_with_an_address_carrying_hidden_characters(): void
    {
        $collector = $this->user('collector1@example.com', User::ROLE_COLLECTOR);

        $response = $this->from('/collector/login')->post('/collector/login', [
            'identity' => "collector1@example.com\u{2069}",
            'password' => 'secret',
        ]);

        $response->assertRedirect(route('collector.dashboard'));
        $this->assertAuthenticatedAs($collector);
    }

    public function test_a_collector_can_sign_in_with_a_space_and_uppercase(): void
    {
        $collector = $this->user('collector1@example.com', User::ROLE_COLLECTOR);

        $this->from('/collector/login')->post('/collector/login', [
            'identity' => " Collector1@Example.com\u{00A0}",
            'password' => 'secret',
        ])->assertRedirect(route('collector.dashboard'));

        $this->assertAuthenticatedAs($collector);
    }

    public function test_a_wrong_password_is_still_refused_however_the_address_is_written(): void
    {
        $this->user('collector1@example.com', User::ROLE_COLLECTOR);

        $this->from('/collector/login')->post('/collector/login', [
            'identity' => "collector1@example.com\u{2069}",
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('identity');

        $this->assertGuest();
    }

    // ---- the panel door ----------------------------------------------------------------

    public function test_an_admin_can_sign_in_with_an_address_carrying_hidden_characters(): void
    {
        $admin = $this->user('admin@example.com', User::ROLE_ADMIN);

        $this->post('/admin/login', [
            'identity' => "\u{200F}admin@\u{200E}example.com ",
            'password' => 'secret',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_an_address_of_nothing_but_invisibles_is_a_validation_error_not_a_crash(): void
    {
        $this->post('/admin/login', [
            'identity' => "\u{200F}\u{2069} ",
            'password' => 'secret',
        ])->assertSessionHasErrors('identity');

        $this->assertGuest();
    }
}

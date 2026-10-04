<?php

namespace Tests\Feature\Api;

use App\Models\User;

/**
 * The door of the mobile API: who gets a token, what the token is bound to, and what a phone that
 * has no valid token is told.
 */
class ApiAuthTest extends ApiTestCase
{
    public function test_a_phone_signs_in_with_an_email_or_a_username_and_gets_a_token(): void
    {
        foreach (['jaber@x.com', 'jaber'] as $typed) {
            $response = $this->withHeaders(['Accept' => 'application/json'])
                ->postJson('/api/v1/login', ['identity' => $typed, 'password' => 'secret']);

            $response->assertOk()
                ->assertJsonPath('user.name', 'جابر')
                ->assertJsonPath('user.role', User::ROLE_COLLECTOR);
            $this->assertNotEmpty($response->json('token'));
        }
    }

    public function test_the_wrong_password_is_refused_and_no_token_is_issued(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/v1/login', ['identity' => 'jaber@x.com', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'auth_failed');

        $this->assertSame(0, $this->collector->tokens()->count());
    }

    public function test_an_identity_a_phone_keyboard_left_a_mark_in_still_signs_in(): void
    {
        // The office types an address on a phone; probes keep finding invisible characters in it.
        $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/v1/login', ['identity' => "\u{200F}jaber@x.com\u{200E} ", 'password' => 'secret'])
            ->assertOk();
    }

    public function test_a_retired_account_cannot_sign_in(): void
    {
        $this->collector->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/v1/login', ['identity' => 'jaber@x.com', 'password' => 'secret'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'account_disabled');

        $this->assertSame(0, $this->collector->tokens()->count());
    }

    public function test_a_retired_account_stops_working_at_the_next_request_not_the_next_sign_in(): void
    {
        $token = $this->token($this->collector);

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->collector->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $this->freshRequest();

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'account_disabled');
    }

    public function test_a_phone_with_no_token_is_told_401_in_json_and_never_shown_a_login_page(): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])->getJson('/api/v1/me');

        $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_phone_that_forgets_the_json_header_still_gets_json(): void
    {
        // A client that omits Accept is the normal case for a first build; an HTML redirect to the
        // collector's login page would parse as a body of zero fields and hide the real failure.
        $this->get('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_signing_out_revokes_the_token_that_signed_in_and_leaves_the_others(): void
    {
        $phone = $this->token($this->collector, 'phone');
        $tablet = $this->token($this->collector, 'tablet');

        $this->withToken($phone)->postJson('/api/v1/logout')->assertOk();

        $this->freshRequest();

        $this->withToken($phone)->getJson('/api/v1/me')->assertStatus(401);
        $this->freshRequest();
        $this->withToken($tablet)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(1, $this->collector->tokens()->count());
    }

    public function test_the_sign_in_answers_with_no_password_or_token_hash(): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/v1/login', ['identity' => 'jaber@x.com', 'password' => 'secret'])->assertOk();

        $this->assertArrayNotHasKey('password', $response->json('user'));
        $this->assertArrayNotHasKey('remember_token', $response->json('user'));
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_validation_is_answered_as_a_field_map(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/v1/login', ['identity' => 'jaber@x.com'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['password']]]);
    }
}

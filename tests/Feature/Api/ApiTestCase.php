<?php

namespace Tests\Feature\Api;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The mobile contract's fixtures.
 *
 * Two rules are baked in here because both have bitten this repository before: the month is READ
 * from the clock (`BillingService::currentPeriod()`), never fixed in a constant — a suite pinned to
 * a month goes red on the 1st for a reason that has nothing to do with the code — and every house
 * is built through the same services the screens use, so a fixture cannot assert a shape the engine
 * would not produce.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $collector;

    protected Route $area;

    protected string $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->person('مدير المكتب', 'admin@x.com', User::ROLE_ADMIN);
        $this->collector = $this->person('جابر', 'jaber@x.com', User::ROLE_COLLECTOR);
        $this->area = Route::create(['name' => 'حي البرج']);
        $this->period = BillingService::currentPeriod();
    }

    protected function person(string $name, string $email, string $role): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'secret',
            'role' => $role,
            'locale' => 'ar',
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string,mixed> $extra */
    protected function flatHouse(string $name, float $fee, array $periods = [], array $extra = []): Subscriber
    {
        $subscriber = Subscriber::create(array_merge([
            'route_id' => $this->area->id,
            'name' => $name,
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => $fee,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ], $extra));

        foreach ($periods as $period) {
            BillingService::ensureFlatBill($subscriber, $period);
        }

        return $subscriber;
    }

    /** @param array<string,mixed> $extra */
    protected function meteredHouse(string $name, float $opening = 1000, array $extra = []): Subscriber
    {
        return Subscriber::create(array_merge([
            'route_id' => $this->area->id,
            'name' => $name,
            'type' => Subscriber::TYPE_METERED,
            'opening_reading' => $opening,
            'opening_period' => BillingService::periodBefore($this->period),
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ], $extra));
    }

    protected function bill(Subscriber $subscriber, string $period): Bill
    {
        return BillingService::ensureFlatBill($subscriber, $period);
    }

    protected function token(User $user, string $name = 'phone'): string
    {
        return $user->createToken($name)->plainTextToken;
    }

    /** A receipt taken the way the office takes one, without the HTTP layer in the way. */
    protected function pay(Subscriber $subscriber, float $amount, ?User $collector = null, ?string $date = null): Payment
    {
        $payment = BillingService::collect(
            $subscriber,
            $amount,
            ($collector ?? $this->collector)->id,
            null,
            $this->period,
        )['payment'];

        if ($date !== null) {
            $payment->forceFill(['collected_on' => $date])->save();
        }

        return $payment;
    }

    /** Call the API as $user, with the header the app always sends. */
    protected function api(User $user, string $method, string $uri, array $body = [], array $headers = []): TestResponse
    {
        $this->freshRequest();

        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer '.$this->token($user),
            'Accept' => 'application/json',
        ], $headers))->json($method, $uri, $body);
    }

    /**
     * Every call is a fresh phone request.
     *
     * Laravel keeps the guard it resolved for the FIRST request inside one test process, so a token
     * revoked — or an account retired — between two calls still answers with the user it cached, and
     * a test that should see the door close sees a 200. A real phone gets a fresh boot per request;
     * this is what makes the test process behave like one. Any raw `withToken(...)` call in a test
     * that changes who is holding the phone, or what state their account is in, must call this first.
     */
    protected function freshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** Money as the wire carries it: a decimal string, never a float. */
    protected function dec(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}

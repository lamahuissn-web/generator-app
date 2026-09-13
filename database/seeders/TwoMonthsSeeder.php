<?php

namespace Database\Seeders;

use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Currency;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two months of a small network: ten subscribers, July and August 2026, and nothing else.
 *
 * This is the sample the office asked for — a ledger with exactly two closed cycles, so a report,
 * a subscriber file or a statement can be read end to end without three later months of noise in
 * the middle. It is NOT the place for meter stories (a dead meter, a swap, a special invoice):
 * those live in `DemoSeeder`, which paints a much wider picture.
 *
 * RUNNING IT WIPES THE PROGRAM. Every table this ledger is made of is emptied first — subscribers,
 * readings, bills, payments and their allocations, handovers, expenses, routes, tariffs, users —
 * so the command is repeatable: run it twice and the ledger is the same both times, rather than two
 * networks piled on top of each other. That is deliberate and it is why the wipe is documented here
 * instead of hidden: `php artisan db:seed --class=TwoMonthsSeeder` means "reset to the two-month
 * sample". Use `CleanSeeder` alone for an empty program.
 *
 * What it leaves behind, per meter:
 *
 *   - a baseline reading at the end of June (no bill: it is the value July reads from);
 *   - a July reading and its bill, priced at July's tariff;
 *   - an August reading and its bill, priced at August's tariff — the ministry raised the price
 *     between the two months on purpose, so a July bill keeps July's price on its own paper;
 *   - flat-rate houses get their two monthly bills and no meter at all;
 *   - the payments of a real month: settled, part-paid, unsettled, one settled in lira, plus two
 *     collectors' handovers and two months of expenses.
 *
 * Every figure comes from `BillingService` — the same calls the office's screens make — so the
 * sample can never disagree with the program that produced it.
 */
class TwoMonthsSeeder extends Seeder
{
    /** Every table this sample is made of — wiped first, then counted from one again. */
    private const LEDGER_TABLES = [
        'payment_allocations', 'payments', 'bill_items', 'bills', 'readings',
        'cash_handovers', 'expenses', 'tariffs', 'subscribers', 'routes', 'users',
    ];

    /** The first billed cycle. */
    public const JULY = '2026-07';

    /** The second billed cycle. */
    public const AUGUST = '2026-08';

    /** The baseline the first cycle reads from — a value, not a month of billing. */
    public const OPENING = '2026-06';

    /** The ministry's price, raised between the two cycles. */
    private const KWH_JULY = 0.25;

    private const KWH_AUGUST = 0.30;

    /** Lira per dollar, for the one receipt taken in lira. */
    private const LBP_RATE = 89500;

    /**
     * The network: three areas, ten houses — six with meters, four on a flat rate.
     *
     * `opening` is the meter at the end of June, `july` and `august` are what it read at the end of
     * each cycle — so consumption is the difference, exactly as a collector's paper works. A house's
     * consumption follows its amperage: a 5A flat runs 30–40 kWh a month, a 20A one around 115.
     *
     * `jul_pay` / `aug_pay` say what happened to that month's bill: `'all'` settled it in full,
     * a number is a part payment, `null` is a bill nobody paid, and `['lbp' => true]` is a house that
     * paid in lira at the counter. `collector` is who took the money (0 = Jad, 1 = Rami).
     *
     * @var array<int, array<string, mixed>>
     */
    private array $plan = [
        // Area 1 — Ramlet El Baida
        [
            'route' => 0, 'name' => 'محمد عيتاني', 'phone' => '03 123456',
            'meter' => '1123', 'ampere' => 10, 'maint' => 25, 'opening' => 1000, 'july' => 1060, 'august' => 1118,
            'jul_pay' => 'all', 'aug_pay' => 'all', 'collector' => 0,
        ],
        [
            'route' => 0, 'name' => 'رنا الحاج', 'phone' => '70 555123',
            'meter' => '1124', 'ampere' => 5, 'maint' => 15, 'opening' => 800, 'july' => 835, 'august' => 872,
            'jul_pay' => 'all', 'jul_method' => Payment::METHOD_WHISH,
            'aug_pay' => ['lbp' => true], 'collector' => 1,
        ],
        [
            'route' => 0, 'name' => 'جورج صليبا', 'phone' => '76 444000',
            'meter' => '1125', 'ampere' => 20, 'maint' => 40, 'opening' => 2400, 'july' => 2515, 'august' => 2628,
            'jul_pay' => null, 'aug_pay' => null, 'collector' => 0,
        ],
        [
            'route' => 0, 'name' => 'ناديا خليل', 'phone' => '03 987654',
            'flat' => 60, 'jul_pay' => 'all', 'aug_pay' => 'all', 'collector' => 0,
        ],
        [
            'route' => 0, 'name' => 'زياد قانصو', 'phone' => '81 222333',
            'flat' => 90, 'jul_pay' => 40, 'aug_pay' => null, 'collector' => 1,
        ],

        // Area 2 — Ain El Remmaneh
        [
            'route' => 1, 'name' => 'علي شرارة', 'phone' => '71 111222',
            'meter' => '2201', 'ampere' => 5, 'maint' => 15, 'opening' => 400, 'july' => 436, 'august' => 470,
            'jul_pay' => 'all', 'aug_pay' => null, 'collector' => 1,
        ],
        [
            'route' => 1, 'name' => 'لينا مرعب', 'phone' => '03 555789',
            'meter' => '2202', 'ampere' => 10, 'maint' => 25, 'opening' => 900, 'july' => 958, 'august' => 1019,
            'jul_pay' => 'all', 'jul_method' => Payment::METHOD_OMT, 'aug_pay' => 20, 'collector' => 0,
        ],
        [
            'route' => 1, 'name' => 'ريم أبو جودة', 'phone' => '79 000111',
            'flat' => 60, 'jul_pay' => null, 'aug_pay' => null, 'collector' => 1,
        ],

        // Area 3 — Tariq El Jdideh
        [
            'route' => 2, 'name' => 'حسن عواد', 'phone' => '76 888999',
            'meter' => '3301', 'ampere' => 5, 'maint' => 15, 'opening' => 100, 'july' => 131, 'august' => 165,
            'jul_pay' => 'all', 'aug_pay' => 'all', 'collector' => 0,
        ],
        [
            'route' => 2, 'name' => 'سامي دندشي', 'phone' => '81 444555',
            'flat' => 90, 'jul_pay' => 'all', 'aug_pay' => 'all', 'aug_method' => Payment::METHOD_OMT, 'collector' => 1,
        ],
    ];

    /** The areas, in the order `route` above refers to them. */
    private array $areas = [
        ['name' => 'رملة البيضا', 'notes' => 'الجهة البحرية'],
        ['name' => 'عين الرمانة', 'notes' => 'الحي العلوي'],
        ['name' => 'طريق الجديدة', 'notes' => 'الحي القديم'],
    ];

    private ?User $admin = null;

    public function run(): void
    {
        $this->wipe();
        $this->seedBase();
        $this->seedNetwork();
        $this->seedCycles();
        $this->seedHandovers();
        $this->seedExpenses();

        $this->command?->info(sprintf(
            'Two months ready — %d subscribers, %d bills, %d receipts, $%s outstanding.',
            Subscriber::count(),
            Bill::count(),
            Payment::count(),
            number_format((float) Subscriber::query()->get()->sum(fn (Subscriber $s): float => $s->outstanding()), 2),
        ));
    }

    /**
     * Empty the ledger.
     *
     * The tables are cleared rather than dropped-and-migrated: the schema is not this seeder's
     * business, and a `migrate:fresh` would take the office's settings and their login with it.
     * Foreign keys are off for the duration because the order that satisfies them all is not worth
     * maintaining by hand in a file whose whole job is to leave nothing behind.
     */
    private function wipe(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            foreach (self::LEDGER_TABLES as $table) {
                DB::table($table)->delete();
            }
        });

        // Sessions and the cache outlive the ledger otherwise: a signed-in browser would keep a user
        // id that no longer exists, and a cached figure would describe a network that is gone.
        foreach (['sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs'] as $table) {
            DB::table($table)->delete();
        }

        // A wiped table keeps counting from where it stopped, and the numbers the office reads embed
        // those ids: invoice "INV-2026-07-0035" and receipt "REC-2026-08-0052" are only clean if the
        // sample starts from one every time it is built. MySQL resets the counter on the table; SQLite
        // keeps its own sequence row, and has neither to reset when it never used one.
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            foreach ([...self::LEDGER_TABLES, 'bill_items'] as $table) {
                DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
            }
        } elseif ($driver === 'sqlite' && Schema::hasTable('sqlite_sequence')) {
            DB::table('sqlite_sequence')->delete();
        }
    }

    private function seedBase(): void
    {
        $this->call(CleanSeeder::class);

        Setting::set('company_name', 'مولّد البرج — أبو حسن', 'Printed in the header of bills and receipts');
        Setting::set('company_phone', '03 123 456', 'Printed contact phone');
        Setting::set('company_address', 'البرج — الشارع الرئيسي', 'Printed address / neighbourhood');
        Setting::set('bill_footer', 'الرجاء تسديد الاشتراك عند زيارة المحصّل', 'Printed note at the bottom of a bill');

        // The counter rate, kept in the settings table like every other figure the office can change.
        // One receipt below is taken in lira, and with no rate the service refuses it — a refusal the
        // office would rightly read as a bug in the sample data.
        Setting::set(Currency::RATE_KEY, (string) self::LBP_RATE, 'سعر صرف الليرة مقابل الدولار الواحد');

        $this->admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        // One tariff per billed cycle. July's price is the one the bills of July keep forever, even
        // after August's rise — the rule the office asked for, visible in the sample.
        foreach ([
            [self::JULY, self::KWH_JULY, 'تسعيرة وزارة الطاقة — تموز'],
            [self::AUGUST, self::KWH_AUGUST, 'تسعيرة وزارة الطاقة — آب'],
        ] as [$period, $price, $note]) {
            Tariff::create([
                'period' => $period,
                'kwh_price' => $price,
                'note' => $note,
                'created_by' => $this->admin->id,
            ]);
        }

        foreach ([
            ['name' => 'Jad Haddad', 'email' => 'collector1@example.com', 'phone' => '03 111222'],
            ['name' => 'Rami Nasser', 'email' => 'collector2@example.com', 'phone' => '71 333444'],
        ] as $row) {
            User::create($row + ['password' => 'password', 'role' => User::ROLE_COLLECTOR]);
        }
    }

    private function seedNetwork(): void
    {
        foreach ($this->areas as $area) {
            Route::create($area);
        }

        $routeIds = Route::orderBy('id')->pluck('id')->all();

        foreach ($this->plan as $i => $row) {
            Subscriber::create([
                'route_id' => $routeIds[$row['route']],
                'name' => $row['name'],
                'phone' => $row['phone'],
                'meter_no' => $row['meter'] ?? null,
                'type' => isset($row['flat']) ? Subscriber::TYPE_FLAT : Subscriber::TYPE_METERED,
                'amperage' => $row['ampere'] ?? null,
                'maintenance_fee' => $row['maint'] ?? 0,
                'maintenance_mode' => $row['mode'] ?? Subscriber::MAINT_CUSTOM,
                'flat_fee' => $row['flat'] ?? null,
                'status' => Subscriber::STATUS_ACTIVE,
                'created_by' => $this->admin->id,
                // Joined before the first cycle they are billed for.
                'created_at' => Carbon::create(2026, 6, 12 + $i),
            ]);
        }
    }

    /**
     * The two cycles: read, bill, collect.
     *
     * Every step goes through `BillingService`, so the bills carry the tariff of their own month,
     * the payments are allocated oldest-first, and the lira receipt stores the rate it was taken at.
     */
    private function seedCycles(): void
    {
        $collectors = User::where('role', User::ROLE_COLLECTOR)->orderBy('id')->pluck('id')->all();
        $subscribers = Subscriber::orderBy('id')->get();

        foreach ($this->plan as $i => $row) {
            $sub = $subscribers[$i];
            $collector = $collectors[$row['collector'] ?? 0];

            if (isset($row['flat'])) {
                // A flat house has no meter: its bill is its rate, both cycles, and nothing to read.
                foreach ([[self::JULY, 25], [self::AUGUST, 25]] as [$period, $day]) {
                    $bill = BillingService::ensureFlatBill($sub, $period);
                    $this->touchDates([$bill], Carbon::createFromFormat('Y-m-d', $period.'-'.$day));
                }
            } else {
                // The value July reads from: a baseline, not a month of billing.
                $baseline = BillingService::recordBaselineReading(
                    $sub,
                    self::OPENING,
                    (float) $row['opening'],
                    $collector,
                    'قراءة أساس — نهاية حزيران',
                );
                $this->touchDates([$baseline], Carbon::create(2026, 6, 27 + ($i % 3), 9, 30));

                foreach ([[self::JULY, 'july', 26], [self::AUGUST, 'august', 27]] as [$period, $key, $day]) {
                    $result = BillingService::enterMeteredReading(
                        $sub,
                        $period,
                        (float) $row[$key],
                        $collector,
                        'قراءة على العدّاد',
                    );
                    $this->touchDates(
                        [$result['reading'], $result['bill']],
                        Carbon::createFromFormat('Y-m-d', $period.'-'.$day)->setTime(10, 15),
                    );
                }
            }

            $this->collect($sub, $row, 'jul_pay', $row['jul_method'] ?? Payment::METHOD_CASH_USD, self::JULY, 3, $collector);
            $this->collect($sub, $row, 'aug_pay', $row['aug_method'] ?? Payment::METHOD_CASH_USD, self::AUGUST, 5, $collector);
        }
    }

    /**
     * One cycle's collection for one house — the visit the collector makes for THAT month's bill.
     *
     * Money is dated in the month after the cycle it settles, as it is in life: July's bill is paid
     * when the collector comes round in August. What a visit settles is therefore not "everything
     * this house owes" but "this cycle and whatever older arrears ride with it":
     *
     *   - July's visit settles what was due before August — July's bill;
     *   - August's visit settles what is still owed when it happens, arrears included.
     *
     * `'all'` asks the ledger for that figure rather than trusting arithmetic written down here: a
     * sample whose amounts are typed in by hand is a sample that can disagree with the program, and
     * every bill in it already carries its own arithmetic.
     *
     * @param  array<string, mixed>  $row
     */
    private function collect(Subscriber $sub, array $row, string $key, string $method, string $period, int $day, int $collectorId): void
    {
        $pay = $row[$key] ?? null;

        if ($pay === null) {
            return;
        }

        $sub = $sub->fresh();

        $target = $period === self::JULY
            ? round(BillingService::outstandingBefore($sub, self::AUGUST), 2)
            : round($sub->outstanding(), 2);

        // Nothing owing means there is no receipt to write: a collector does not collect a zero.
        if ($target <= 0) {
            return;
        }

        $month = $period === self::JULY ? 8 : 9;
        $date = Carbon::create(2026, $month, min($day + 2, 27), 11, 20);

        if (is_array($pay) && ($pay['lbp'] ?? false)) {
            // Settled in lira: the counter takes the whole cycle in cash pounds at the day's rate.
            $result = BillingService::collectInLira(
                $sub,
                round($target * self::LBP_RATE),
                self::LBP_RATE,
                $collectorId,
                'تسديد بالليرة على الكاونتر',
                $period,
            );
            $this->datePayment($result['payment'], $date);

            return;
        }

        // A part payment larger than the cycle would be change handed back, not a receipt.
        $amount = $pay === 'all' ? $target : min((float) $pay, $target);

        $result = BillingService::collect(
            $sub,
            $amount,
            $collectorId,
            $pay === 'all' ? 'تسديد الفاتورة كاملة' : 'دفعة جزئية',
            $period,
            $method,
        );

        $this->datePayment($result['payment'], $date);
    }

    /** What the collectors handed to the office: a full August settlement from each, then a part one. */
    private function seedHandovers(): void
    {
        $collectors = User::where('role', User::ROLE_COLLECTOR)->orderBy('id')->get();

        foreach ([
            [0, 150, '2026-08-31', 'تصفية شهر آب'],
            [1, 100, '2026-08-31', 'تصفية شهر آب'],
            [0, 40, '2026-09-08', 'تسليم جزئي'],
        ] as [$idx, $amount, $date, $note]) {
            $handover = CashHandover::create([
                'collector_id' => $collectors[$idx]->id,
                'amount' => $amount,
                'handover_date' => $date,
                'note' => $note,
                'recorded_by' => $this->admin->id,
            ]);

            DB::table('cash_handovers')->where('id', $handover->id)->update([
                'created_at' => Carbon::parse($date.' 18:30'),
                'updated_at' => Carbon::parse($date.' 18:30'),
            ]);
        }
    }

    /** Two months of running costs, so profit and loss has two columns to compare. */
    private function seedExpenses(): void
    {
        foreach ([
            ['fuel', 45, 150, '2026-07-05', 'مازوت للمولّد 1'],
            ['oil', 15, null, '2026-07-15', 'تبديل زيت'],
            ['rent', 40, null, '2026-07-01', 'إيجار الموقع'],
            ['salary', 100, null, '2026-07-31', 'راتب المحصّلين — تموز'],
            ['fuel', 48, 160, '2026-08-04', 'مازوت للمولّد 2'],
            ['oil', 18, null, '2026-08-20', 'زيت + فلتر'],
            ['rent', 40, null, '2026-08-01', 'إيجار الموقع'],
            ['repair', 25, null, '2026-08-12', 'صيانة المولّد'],
            ['salary', 100, null, '2026-08-31', 'راتب المحصّلين — آب'],
        ] as [$category, $amount, $qty, $date, $description]) {
            $expense = Expense::create([
                'category' => $category,
                'amount' => $amount,
                'quantity' => $qty,
                'expense_date' => $date,
                'description' => $description,
                'entered_by' => $this->admin->id,
            ]);

            DB::table('expenses')->where('id', $expense->id)->update([
                'created_at' => Carbon::parse($date.' 09:00'),
                'updated_at' => Carbon::parse($date.' 09:00'),
            ]);
        }
    }

    /**
     * A receipt's dates — and its number, which is minted from the month it was written in.
     *
     * A payment created today and then dated back to August keeps a September number unless it is
     * recomputed (the app mints `REC-YYYY-MM-####` from the row's own date): the office would read a
     * list of receipts numbered 09 dated 08 and rightly not trust it. Renumbered here with the app's
     * own rule for the number, from the date the row now claims — the id makes it unique either way.
     */
    private function datePayment(Payment $payment, Carbon $date): void
    {
        $this->touchDates([$payment], $date);

        DB::table('payments')->where('id', $payment->getKey())->update([
            'number' => BillingService::receiptNumber($date, $payment->getKey()),
        ]);
    }

    /**
     * A row's dates set to when it happened.
     *
     * `created_at` is the only field the panel's own figures are dated by (today's takings, the
     * aging of a debt, the month a handover belongs to), so a sample that leaves every row stamped
     * "the minute the seeder ran" produces a ledger whose history is all one afternoon.
     *
     * @param  Model[]  $models
     */
    private function touchDates(array $models, Carbon $date): void
    {
        foreach ($models as $model) {
            DB::table($model->getTable())->where('id', $model->getKey())->update([
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        }
    }
}

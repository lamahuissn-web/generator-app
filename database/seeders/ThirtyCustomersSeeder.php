<?php

namespace Database\Seeders;

use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Thirty households, three worked cycles (July, August, September 2026), four streets.
 *
 * This is SAMPLE data for the phone and the office: every reading and every bill comes from
 * `BillingService` — the same engine the door uses — so the balances, the unpaid months, the
 * allocations and the receipts are internally true rather than plausible-looking rows. A fake
 * `paid_amount` would have made the sample lie about the one thing this program is for.
 *
 * The mix is deliberate, because an empty screen proves nothing:
 *
 *   - payment habits: paid all three, paid two, paid one, paid nothing, paid part of a month,
 *     and one who settled everything only in September (so FIFO has to travel back three months);
 *   - a house whose meter did not move (a zero, confirmed after the warning), and one that
 *     quadrupled (a jump, confirmed) — both carry `anomaly_confirmed`, so the log shows the badge;
 *   - a dead meter estimated for one month, a few flat-rate houses, two inactive accounts;
 *   - cash, Whish and OMT rails, so receipts are not all one method.
 *
 * October is left UNREAD on purpose: that is the collector's current work when the app opens.
 *
 * Dev only: `php artisan db:seed --class=ThirtyCustomersSeeder`.
 */
class ThirtyCustomersSeeder extends Seeder
{
    private ?User $admin = null;

    private ?int $collector = null;

    /** name, phone, amperage, maintenance fee, street index, base monthly kWh. */
    private array $houses = [
        ['علي حسن شرارة', '03 123401', 10, 25, 0, 95],
        ['فاطمة الزهراء نصر', '70 555402', 5, 15, 0, 55],
        ['محمد أحمد عيتاني', '76 444403', 20, 40, 0, 140],
        ['رنا جورج الحاج', '03 987404', 10, 25, 0, 80],
        ['جورج إلياس صليبا', '71 222405', 20, 40, 0, 130],
        ['ناديا سليم خليل', '81 333406', 5, 15, 0, 45],
        ['زياد فؤاد قانصو', '03 111407', 10, 25, 0, 100],
        ['حسن علي عواد', '71 666408', 5, 15, 0, 60],
        ['لينا سمير مرعب', '03 555409', 10, 25, 1, 75],
        ['ريم أنطون أبو جودة', '79 000410', 5, 15, 1, 50],
        ['سامي رشيد دندشي', '81 444411', 20, 40, 1, 120],
        ['خليل محمود حداد', '70 000412', 10, 25, 1, 90],
        ['جوزيف جوزيف حرب', '70 998413', 10, 25, 1, 85],
        ['مروان عمر الخطيب', '03 222414', 5, 15, 1, 48],
        ['هدى مصطفى سعادة', '76 555415', 10, 25, 1, 70],
        ['عباس علي حجازي', '71 777416', 15, 30, 1, 110],
        ['نورا جمال شهاب', '03 333417', 20, 40, 2, 135],
        ['طارق سامي بيضون', '79 111418', 10, 25, 2, 88],
        ['إيمان رفيق حمزة', '81 222419', 5, 15, 2, 52],
        ['بسام كمال زين الدين', '70 333420', 10, 25, 2, 78],
        ['سارة نبيل مطر', '03 444421', 15, 30, 2, 105],
        ['وسام غسان الصايغ', '76 666422', 20, 40, 2, 125],
        ['عمر خالد الشامي', '71 888423', 10, 25, 2, 92],
        ['ليلى أحمد منصور', '03 555424', 5, 15, 3, 58],
        ['حسين محمد درويش', '79 222425', 10, 25, 3, 82],
        ['أنطوانيت يوسف كرم', '81 333426', 20, 40, 3, 128],
        ['بلال سعد الدين', '70 444427', 10, 25, 3, 74],
        ['ماجدة علي فواز', '03 666428', 5, 15, 3, 46],
        ['غسان ريمون عبد النور', '76 777429', 15, 30, 3, 112],
        ['رامي نزيه الحاج علي', '71 999430', 10, 25, 3, 86],
    ];

    /** Who pays what: three months, two, one, none, or only part of one. */
    private const HABITS = ['paid3', 'paid2', 'paid1', 'none', 'partial', 'paid3', 'sep_only'];

    public function run(): void
    {
        $this->admin = User::where('role', User::ROLE_ADMIN)->orderBy('id')->firstOrFail();
        $this->collector = User::where('role', User::ROLE_COLLECTOR)->orderBy('id')->value('id');

        if ($this->collector === null) {
            throw new \RuntimeException('No collector account exists — the sample needs one to attribute the cash.');
        }

        $this->wipe();
        $this->seed();
        $this->summary();
    }

    /**
     * Everything the sample replaces, and nothing it needs to keep.
     *
     * Users stay (they are logins), the four streets stay, the settings and the kwh price stay:
     * deleting those would break the app rather than reset its books. The 40 empty test streets
     * made for the scale experiment are removed too — they are scaffolding, not a neighbourhood.
     */
    private function wipe(): void
    {
        DB::transaction(function (): void {
            DB::table('payment_allocations')->delete();
            DB::table('bill_items')->delete();
            DB::table('bills')->delete();
            DB::table('payments')->delete();
            DB::table('readings')->delete();
            DB::table('cash_handovers')->delete();
            DB::table('expenses')->delete();
            DB::table('refuellings')->delete();
            DB::table('equipment_services')->delete();
            DB::table('equipment')->delete();
            DB::table('subscribers')->delete();
            DB::table('api_operations')->delete();
            DB::table('audit_logs')->delete();

            Route::where('notes', 'بيانات تجربة — تُحذف')->delete();
        });

        $this->command?->info('Wiped: households, bills, payments, readings, handovers, expenses.');
    }

    private function seed(): void
    {
        $routes = Route::orderBy('id')->pluck('id')->values()->all();

        if (count($routes) < 4) {
            throw new \RuntimeException('The four streets must exist before seeding (found '.count($routes).').');
        }

        DB::transaction(function () use ($routes): void {
            foreach ($this->houses as $i => [$name, $phone, $ampere, $maint, $street, $base]) {
                $flat = in_array($i, [6, 17, 25, 29], true);
                $inactive = in_array($i, [10, 27], true);
                $habit = self::HABITS[$i % count(self::HABITS)];

                $subscriber = Subscriber::create([
                    'route_id' => $routes[$street],
                    'name' => $name,
                    'phone' => $phone,
                    'meter_no' => $flat ? null : 'M-'.(1001 + $i),
                    'type' => $flat ? Subscriber::TYPE_FLAT : Subscriber::TYPE_METERED,
                    'amperage' => $flat ? null : $ampere,
                    'maintenance_fee' => $maint,
                    'maintenance_mode' => Subscriber::MAINT_CUSTOM,
                    'flat_fee' => $flat ? [60, 90, 75, 120][$i % 4] : null,
                    'status' => $inactive ? Subscriber::STATUS_INACTIVE : Subscriber::STATUS_ACTIVE,
                    'notes' => 'عيّنة تجريبية',
                    'created_by' => $this->admin->id,
                ]);

                DB::table('subscribers')->where('id', $subscriber->id)->update([
                    'created_at' => Carbon::create(2026, 6, 20 + ($i % 8), 9, 0),
                    'updated_at' => Carbon::create(2026, 6, 20 + ($i % 8), 9, 0),
                ]);

                if ($inactive) {
                    continue;
                }

                $flat
                    ? $this->seedFlatCycle($subscriber, $habit, $i)
                    : $this->seedMeteredCycle($subscriber, $habit, $base, $i);
            }

            $this->seedHandovers();
            $this->seedExpenses();
        });
    }

    /** July, August, September for a house on a meter: an opening figure, then three real readings. */
    private function seedMeteredCycle(Subscriber $subscriber, string $habit, int $base, int $i): void
    {
        $opening = 1000 + ($i * 37);
        $date = Carbon::create(2026, 6, 25, 9, 0);

        $row = Reading::create([
            'subscriber_id' => $subscriber->id,
            'period' => '2026-06',
            'current_reading' => $opening,
            'previous_reading' => 0,
            'consumption' => 0,
            'entered_by' => $this->collector,
            'note' => 'قراءة افتتاحية',
        ]);
        $this->touch($row, $date);

        $meter = $opening;
        $paid = ['jul' => false, 'aug' => false, 'sep' => false];

        foreach (['2026-07' => ['jul', 25], '2026-08' => ['aug', 24], '2026-09' => ['sep', 5]] as $period => [$key, $day]) {
            // A believable month: the house's own level, breathing a little (summer is the fat month).
            $factor = ['jul' => 1.0, 'aug' => 1.12, 'sep' => 0.94][$key];
            $consumption = (int) round($base * $factor * (1 + (($i % 5) - 2) * 0.03));

            // Two houses are the office's exceptions, and both are CONFIRMED here: one meter that did
            // not move (a seasonal flat, a locked shop) and one that went through the roof (a new AC).
            $confirmed = false;
            if ($i === 5 && $period === '2026-08') {
                $consumption = 0;
                $confirmed = true;
            }
            if ($i === 12 && $period === '2026-09') {
                $consumption = (int) round($base * 3.6);
                $confirmed = true;
            }

            $meter += $consumption;
            $readAt = Carbon::create((int) substr($period, 0, 4), (int) substr($period, 5, 2), $day, 10, 15);

            // One dead meter, estimated: the office says what it charges instead of reading.
            if ($i === 9 && $period === '2026-09') {
                $result = BillingService::enterEstimatedReading(
                    $subscriber,
                    $period,
                    60,
                    'العدّاد معطّل — بانتظار الاستبدال',
                    $this->collector,
                );
            } else {
                $result = BillingService::enterMeteredReading(
                    $subscriber,
                    $period,
                    (float) $meter,
                    $this->collector,
                    'قراءة على العدّاد',
                    $confirmed ? ['confirmed_anomaly' => true] : [],
                );
            }

            $this->touch($result['reading'], $readAt);
            $this->touch($result['bill'], $readAt->copy()->addHours(2));

            if ($this->pays($habit, $key)) {
                $this->collect($subscriber, $period, $this->amountFor($subscriber, $period, $habit, $key), $readAt->copy()->addDays(2));
                $paid[$key] = true;
            }
        }
    }

    /** July, August, September for a flat-rate house: the bill is the fee, nothing to read. */
    private function seedFlatCycle(Subscriber $subscriber, string $habit, int $i): void
    {
        foreach (['2026-07' => ['jul', 3], '2026-08' => ['aug', 3], '2026-09' => ['sep', 3]] as $period => [$key, $day]) {
            $bill = BillingService::ensureFlatBill($subscriber, $period);
            $raisedAt = Carbon::create((int) substr($period, 0, 4), (int) substr($period, 5, 2), $day, 8, 30);
            $this->touch($bill, $raisedAt);

            if ($this->pays($habit, $key)) {
                $this->collect($subscriber, $period, (float) $subscriber->flat_fee, $raisedAt->copy()->addDays(3));
            }
        }
    }

    private function pays(string $habit, string $month): bool
    {
        return match ($habit) {
            'paid3' => true,
            'paid2' => $month !== 'sep',
            'paid1' => $month === 'jul',
            'none' => false,
            'partial' => $month === 'aug',
            'sep_only' => $month === 'sep',
            default => false,
        };
    }

    private function amountFor(Subscriber $subscriber, string $period, string $habit, string $month): float
    {
        $monthBill = $subscriber->bills()->where('period', $period)->orderBy('id')->first();
        $billTotal = $monthBill ? (float) $monthBill->total : (float) $subscriber->outstanding();

        // «paid3» and «sep_only» settle what the books say, the way a collector with the app would.
        if ($habit === 'paid3' || $habit === 'sep_only') {
            return round((float) $subscriber->fresh()->outstanding(), 2);
        }

        return match ($habit) {
            'partial' => round($billTotal * 0.45, 2),
            default => $billTotal,
        };
    }

    private function collect(Subscriber $subscriber, string $period, float $amount, Carbon $on): void
    {
        if ($amount <= 0) {
            return;
        }

        $i = $subscriber->id;

        // The rails a collector actually carries: mostly the cash box, some Whish, a few OMT.
        $method = match (true) {
            $i % 3 === 0 => Payment::METHOD_WHISH,
            $i % 5 === 0 => Payment::METHOD_OMT,
            default => Payment::METHOD_CASH_USD,
        };

        $result = BillingService::collect(
            $subscriber,
            $amount,
            $this->collector,
            'قبض على الباب',
            $period,
            $method,
            $on,
        );

        $this->touch($result['payment'], $on);
    }

    private function seedHandovers(): void
    {
        foreach ([
            [200, '2026-08-31', 'تسليم آب'],
            [250, '2026-09-30', 'تسليم أيلول'],
        ] as [$amount, $date, $note]) {
            $handover = CashHandover::create([
                'collector_id' => $this->collector,
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

    private function seedExpenses(): void
    {
        foreach ([
            ['fuel', 150, 480, '2026-07-06', 'مازوت — تموز'],
            ['salary', 300, null, '2026-07-31', 'أجور — تموز'],
            ['fuel', 160, 500, '2026-08-05', 'مازوت — آب'],
            ['repair', 45, null, '2026-08-19', 'صيانة مولد'],
            ['salary', 300, null, '2026-08-31', 'أجور — آب'],
            ['fuel', 145, 460, '2026-09-04', 'مازوت — أيلول'],
            ['salary', 300, null, '2026-09-30', 'أجور — أيلول'],
        ] as [$category, $amount, $qty, $date, $description]) {
            Expense::create([
                'category' => $category,
                'amount' => $amount,
                'quantity' => $qty,
                'expense_date' => $date,
                'description' => $description,
                'entered_by' => $this->admin->id,
            ]);
        }
    }

    /** @param  Model  $model */
    private function touch(Model $model, Carbon $date): void
    {
        DB::table($model->getTable())->where('id', $model->id)->update([
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }

    private function summary(): void
    {
        $this->command?->info(sprintf(
            'Seeded %d households · %d bills · %d payments · %d readings across %d streets.',
            Subscriber::count(),
            Bill::count(),
            Payment::count(),
            Reading::count(),
            Route::count(),
        ));
    }
}

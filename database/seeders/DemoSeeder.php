<?php

namespace Database\Seeders;

use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Expense;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    /** @var array<int, array<string, mixed>> */
    private array $plan = [
        // Route 1 — Ramlet El Baida
        ['route' => 0, 'name' => 'محمد عيتاني', 'phone' => '03 123456', 'meter' => '1123', 'ampere' => 10, 'maint' => 25, 'open' => 1200, 'aug_cur' => 1270, 'sep_cur' => 1340, 'aug_pay' => 42.5, 'sep_pay' => 30, 'collector' => 0],
        ['route' => 0, 'name' => 'رنا الحاج', 'phone' => '70 555123', 'meter' => '1124', 'ampere' => 5, 'maint' => 15, 'mode' => 'auto', 'open' => 800, 'aug_cur' => 856, 'sep_cur' => 902, 'aug_pay' => 99999, 'sep_pay' => null, 'collector' => 1],
        ['route' => 0, 'name' => 'جورج صليبا', 'phone' => '76 444000', 'meter' => '1125', 'ampere' => 20, 'maint' => 40, 'open' => 2400, 'aug_cur' => 2520, 'sep_cur' => null, 'aug_pay' => null, 'sep_pay' => null, 'collector' => 0],
        ['route' => 0, 'name' => 'ناديا خليل', 'phone' => '03 987654', 'flat' => 60, 'aug_pay' => 99999, 'sep_pay' => null, 'collector' => 0],
        ['route' => 0, 'name' => 'زياد قانصو', 'phone' => '81 222333', 'flat' => 90, 'aug_pay' => null, 'sep_pay' => 90, 'collector' => 1],
        // Route 2 — Ain El Remmaneh
        ['route' => 1, 'name' => 'علي شرارة', 'phone' => '71 111222', 'meter' => '2201', 'ampere' => 5, 'maint' => 15, 'open' => 400, 'aug_cur' => 430, 'sep_cur' => 458, 'aug_pay' => null, 'sep_pay' => null, 'collector' => 1],
        ['route' => 1, 'name' => 'لينا مرعب', 'phone' => '03 555789', 'meter' => '2202', 'ampere' => 10, 'maint' => 25, 'open' => 900, 'aug_cur' => 944, 'sep_cur' => 990, 'aug_pay' => 99999, 'sep_pay' => null, 'collector' => 0],
        ['route' => 1, 'name' => 'ريم أبو جودة', 'phone' => '79 000111', 'flat' => 60, 'aug_pay' => 99999, 'sep_pay' => 40, 'collector' => 1],
        // Route 3 — Tariq El Jdideh
        ['route' => 2, 'name' => 'حسن عواد', 'phone' => '76 888999', 'meter' => '3301', 'ampere' => 5, 'maint' => 15, 'open' => 100, 'aug_cur' => 131, 'sep_cur' => 150, 'aug_pay' => null, 'sep_pay' => 20, 'collector' => 0],
        ['route' => 2, 'name' => 'سامي دندشي', 'phone' => '81 444555', 'flat' => 90, 'aug_pay' => null, 'sep_pay' => 90, 'collector' => 0],
        ['route' => 2, 'name' => 'خليل حداد', 'phone' => '70 000777', 'meter' => '3302', 'ampere' => 20, 'maint' => 40, 'open' => 0, 'aug_cur' => 120, 'sep_cur' => null, 'aug_pay' => null, 'sep_pay' => null, 'collector' => 1, 'inactive' => true],
        // Deep-debt case for the aging report: unpaid since July (2+ months → red).
        ['route' => 2, 'name' => 'جوزيف حرب', 'phone' => '70 998877', 'meter' => '3303', 'ampere' => 10, 'maint' => 25, 'open' => 2000, 'open_period' => '2026-06'],
    ];

    public function run(): void
    {
        $this->seedBase();
        $this->seedNetwork();
        $this->seedHistory();
    }

    private function seedBase(): void
    {
        Setting::flush();

        Setting::set('kwh_price', '0.25', 'USD price per kWh applied to new metered bills');
        Setting::set('default_maintenance_fee', '15', 'USD default monthly maintenance for metered subscribers on Auto');
        Setting::set('company_name', 'مولّد البرج — أبو حسن', 'Printed in the header of bills and receipts');
        Setting::set('company_phone', '03 123 456', 'Printed contact phone');
        Setting::set('company_address', 'البرج — الشارع الرئيسي', 'Printed address / neighbourhood');
        Setting::set('bill_footer', 'الرجاء تسديد الاشتراك عند زيارة المحصّل', 'Printed note at the bottom of a bill');

        $admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@example.com',
            'password' => 'admin123', 'role' => User::ROLE_ADMIN,
        ]);
        User::create([
            'name' => 'Jad Haddad', 'email' => 'collector1@example.com',
            'password' => 'password', 'role' => User::ROLE_COLLECTOR, 'phone' => '03 111222',
        ]);
        User::create([
            'name' => 'Rami Nasser', 'email' => 'collector2@example.com',
            'password' => 'password', 'role' => User::ROLE_COLLECTOR, 'phone' => '71 333444',
        ]);

        $this->admin = $admin;

        // Ministry tariff per cycle: past cycles match the demo bills, and the
        // upcoming cycle carries a new price, exactly how MoEW updates it.
        foreach ([
            ['2026-08', 0.25, 'تسعيرة وزارة الطاقة — آب'],
            ['2026-09', 0.25, 'تسعيرة وزارة الطاقة — أيلول'],
            ['2026-10', 0.54, 'تسعيرة وزارة الطاقة — تشرين الأول (جديدة)'],
        ] as [$period, $price, $note]) {
            Tariff::create([
                'period' => $period,
                'kwh_price' => $price,
                'note' => $note,
                'created_by' => $admin->id,
            ]);
        }
    }

    private ?User $admin = null;

    private function seedNetwork(): void
    {
        $routes = [
            ['name' => 'Ramlet El Baida', 'notes' => 'Coast side, 3 generators'],
            ['name' => 'Ain El Remmaneh', 'notes' => 'Hill neighborhood'],
            ['name' => 'Tariq El Jdideh', 'notes' => 'Old quarter'],
        ];
        foreach ($routes as $r) {
            Route::create($r);
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
                'status' => ! empty($row['inactive']) ? Subscriber::STATUS_INACTIVE : Subscriber::STATUS_ACTIVE,
                'created_by' => $this->admin->id,
                'created_at' => Carbon::create(2026, 7, 20 + ($i % 9)),
            ]);
        }
    }

    private function seedHistory(): void
    {
        $subs = Subscriber::orderBy('id')->get();
        $collectors = User::where('role', User::ROLE_COLLECTOR)->orderBy('id')->pluck('id')->all();

        foreach ($this->plan as $i => $row) {
            $sub = $subs[$i];
            $collectorId = $collectors[$row['collector'] ?? 0];

            // Baseline "opening" reading so August bills only cover real usage.
            if (isset($row['open'])) {
                $opening = Reading::create([
                    'subscriber_id' => $sub->id,
                    'period' => $row['open_period'] ?? '2026-07',
                    'current_reading' => (float) $row['open'],
                    'previous_reading' => 0,
                    'consumption' => 0,
                    'entered_by' => $collectorId,
                    'note' => 'Opening reading',
                ]);
                $openDate = ($row['open_period'] ?? '2026-07') === '2026-06'
                    ? Carbon::create(2026, 6, 25, 9, 0)
                    : Carbon::create(2026, 7, 25, 9, 0);
                $this->touchDates([$opening], $openDate);
            }

            // Deep-debt demo: unpaid July + August bills (no September visit yet).
            if (($row['name'] ?? '') === 'جوزيف حرب') {
                [$rJul] = $this->reading($sub, '2026-07', 2044, $collectorId, Carbon::create(2026, 7, 26, 10, 0));
                $this->reading($sub, '2026-08', 2088, $collectorId, Carbon::create(2026, 8, 26, 10, 0));
            }

            // ---------- AUGUST cycle ----------
            $augDate = Carbon::create(2026, 8, 24 + ($i % 6), 10, 15);
            $readingAug = $billAug = null;

            if (isset($row['aug_cur'])) {
                [$readingAug, $billAug] = $this->reading($sub, '2026-08', (float) $row['aug_cur'], $collectorId, $augDate);
            }
            if (isset($row['flat']) && ! isset($row['aug_pay'])) {
                // Flat-rate subscriber that never paid August: bill exists, rolls over.
                $billAug = BillingService::ensureFlatBill($sub, '2026-08');
                $this->touchDates([$billAug], Carbon::create(2026, 8, 25, 9, 0));
            }
            if (isset($row['aug_pay'])) {
                $amount = $row['aug_pay'] === 99999
                    ? (isset($row['flat']) ? (float) $sub->flat_fee : $sub->outstanding())
                    : (float) $row['aug_pay'];
                $payDate = $augDate->copy()->addDays(2)->max(Carbon::create(2026, 8, 27));
                $result = $this->collectRaw($sub, $amount, $collectorId, 'August collection', '2026-08', $payDate);
                $this->touchDates([$result['payment']], $payDate);
            }

            // ---------- SEPTEMBER cycle ----------
            if (isset($row['sep_cur'])) {
                $sepDate = Carbon::create(2026, 9, 1 + ($i % 6), 9, 40);
                $this->reading($sub, '2026-09', (float) $row['sep_cur'], $collectorId, $sepDate);
            }
            if (isset($row['sep_pay'])) {
                $amount = $row['sep_pay'] === 99999
                    ? (isset($row['flat']) ? (float) $sub->flat_fee : $sub->outstanding())
                    : (float) $row['sep_pay'];
                $payDate = Carbon::create(2026, 9, 2 + ($i % 7), 11, 5)->min(Carbon::create(2026, 9, 9, 18));
                // Two rails besides the cash box, so the tables/receipts show methods.
                $method = match ($i) {
                    0 => 'whish',
                    2 => 'cash_lbp',
                    default => 'cash_usd',
                };
                $result = $this->collectRaw($sub, $amount, $collectorId, 'September collection', null, $payDate, $method);
                $this->touchDates([$result['payment']], $payDate);
            }
        }

        $this->seedExpenses();
        $this->seedHandovers();
        $this->seedMeterStories();
    }

    /**
     * October cycle, left half-recorded on purpose so the operator can SEE the meter
     * lifecycle: an estimate (dead meter), a replacement, a normal read, a field report
     * still waiting for a decision, and a subscriber moved to a flat rate for good.
     * The coverage gate on the bulk-issue page shows the resulting mix.
     */
    private function seedMeterStories(): void
    {
        $collectors = User::where('role', User::ROLE_COLLECTOR)->orderBy('id')->pluck('id')->all();
        $sub = fn (string $name): Subscriber => Subscriber::where('name', $name)->firstOrFail();

        // 1) Dead meter, estimated cycle: the meter stays at 1,340 and October bills 60 kWh.
        $estimated = BillingService::enterEstimatedReading(
            $sub('محمد عيتاني'),
            '2026-10',
            60,
            'العدّاد معطّل — بانتظار الاستبدال',
            $collectors[0],
        );
        $this->touchDates(
            [$estimated['reading'], $estimated['bill']],
            Carbon::create(2026, 10, 3, 10, 20),
        );

        // 2) Meter replaced mid-cycle: 1,124 ran to 920 (18 kWh) + the new one at 8 kWh.
        $swap = BillingService::enterMeteredReading(
            $sub('رنا الحاج'),
            '2026-10',
            8,
            $collectors[1],
            'العدّاد القديم توقف — رُكّب عدّاد جديد',
            ['swap' => [
                'old_meter_no' => '1124',
                'old_reading' => 920,
                'new_meter_no' => '4478',
                'new_start' => 0,
            ]],
        );
        $this->touchDates([$swap['reading'], $swap['bill']], Carbon::create(2026, 10, 5, 9, 15));

        // 3) A plain reading, so a normal October bill sits next to the special ones.
        $normal = BillingService::enterMeteredReading($sub('لينا مرعب'), '2026-10', 1035, $collectors[0], 'Meter read on site');
        $this->touchDates([$normal['reading'], $normal['bill']], Carbon::create(2026, 10, 6, 11, 40));

        // 4) Collectors reported a dead meter; nobody has decided yet (badge + gate).
        $sub('جورج صليبا')->reportMeterBroken('العدّاد يوقف ويشتغل كل شوي', $collectors[0]);
        $sub('حسن عواد')->reportMeterBroken('شك بالعدّاد — القراءة ما بتتحرك', $collectors[1]);

        // 5) No meter coming back: a flat rate instead, with the reason on the record.
        $flat = $sub('علي شرارة');
        $flat->convertToFlat(70, 'العدّاد معطّل ولم يُستبدل', $this->admin->id);
        $flatBill = BillingService::ensureFlatBill($flat->fresh(), '2026-10');
        $this->touchDates([$flatBill], Carbon::create(2026, 10, 1, 8, 30));

        $this->seedSpecialInvoices();
    }

    /**
     * Special invoices (charges outside the subscription): two open and one settled, so
     * the Bills list shows every state and the statement names them.
     */
    private function seedSpecialInvoices(): void
    {
        $collectors = User::where('role', User::ROLE_COLLECTOR)->orderBy('id')->pluck('id')->all();

        // A part + labour on a flat subscriber, and a shorter cable job on another.
        $jobs = [
            ['زياد قانصو', 'تركيب عدّاد جديد', 'الضمان 6 أشهر على العدّاد', [
                ['عدّاد جديد 10 أمبير', 1, 45],
                ['أجور فنّي — تركيبة', 2, 12.5],
            ], Carbon::create(2026, 10, 4, 12, 0)],
            ['ريم أبو جودة', 'تمديد كابل', null, [
                ['كابل 3×2.5 (متر)', 12, 1.5],
            ], Carbon::create(2026, 10, 7, 16, 30)],
        ];

        foreach ($jobs as [$name, $title, $note, $lines, $date]) {
            $subscriber = Subscriber::where('name', $name)->firstOrFail();
            $result = BillingService::createSpecialInvoice(
                $subscriber,
                array_map(fn (array $line) => [
                    'description' => $line[0],
                    'quantity' => $line[1],
                    'unit_price' => $line[2],
                ], $lines),
                $title,
                $note,
                '2026-10',
                $this->admin->id,
            );
            $this->touchDates([$result['bill'], ...$result['items']], $date);
        }

        // One that was raised and settled the same week, so a paid special invoice exists.
        // Done on a subscriber with nothing else owing, so FIFO lands the money on it.
        $paidSubscriber = Subscriber::where('name', 'ناديا خليل')->firstOrFail();
        $paid = BillingService::createSpecialInvoice($paidSubscriber, [
            ['description' => 'قطع غيار — فلتر وشمعة', 'quantity' => 2, 'unit_price' => 15],
        ], 'صيانة عدّاد', null, '2026-10', $this->admin->id);
        $raisedAt = Carbon::create(2026, 10, 8, 9, 45);
        $this->touchDates([$paid['bill'], ...$paid['items']], $raisedAt);

        $paidAt = Carbon::create(2026, 10, 9, 10, 30);
        // A flat subscriber's collect() raises the current cycle's subscription bill on the
        // spot, so the payment has to cover that too or the money lands there and the
        // special invoice stays open.
        $currentPeriod = BillingService::currentPeriod();
        $raisesSubscription = $paidSubscriber->isFlat()
            && ! $paidSubscriber->bills()->where('period', $currentPeriod)->where('type', '!=', Bill::TYPE_SPECIAL)->exists();
        $amount = round($paidSubscriber->fresh()->outstanding() + ($raisesSubscription ? (float) $paidSubscriber->flat_fee : 0), 2);

        $settled = $this->collectRaw(
            $paidSubscriber->fresh(),
            $amount,
            $collectors[0],
            'تسديد الفاتورة الخاصة',
            null,
            $paidAt,
            'cash_usd',
        );
        $this->touchDates([$settled['payment']], $paidAt);
    }

    /** Cash the collectors handed to the operator (so the report shows in-hand balances). */
    private function seedHandovers(): void
    {
        $collectors = User::where('role', User::ROLE_COLLECTOR)->orderBy('id')->get();
        $rows = [
            // [collector index, amount, date, note]
            [0, 60, '2026-08-31', 'August settlement'],
            [1, 25, '2026-08-31', 'August settlement'],
            [0, 40, '2026-09-08', 'Partial settlement'],
        ];

        foreach ($rows as [$idx, $amount, $date, $note]) {
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

    private function seedExpenses(): void
    {
        $admin = $this->admin;
        $rows = [
            // [category, amount, quantity (liters), date, description]
            ['fuel', 45, 150, '2026-08-05', 'Diesel for generator 1'],
            ['oil', 15, null, '2026-08-15', 'Engine oil change'],
            ['salary', 100, null, '2026-08-31', 'Collector pay — August'],
            ['rent', 40, null, '2026-08-01', 'Site rent'],
            ['fuel', 40, 130, '2026-09-03', 'Diesel for generator 2'],
            ['oil', 20, null, '2026-09-07', 'Oil + filter'],
            ['salary', 100, null, '2026-09-01', 'Collector pay — September'],
            ['rent', 40, null, '2026-09-01', 'Site rent'],
            ['repair', 25, null, '2026-09-05', 'Generator servicing (spark plugs)'],
        ];

        foreach ($rows as [$category, $amount, $qty, $date, $description]) {
            Expense::create([
                'category' => $category,
                'amount' => $amount,
                'quantity' => $qty,
                'expense_date' => $date,
                'description' => $description,
                'entered_by' => $admin->id,
            ]);
        }
    }

    /** @return array{0: Reading, 1: Bill} */
    private function reading(Subscriber $sub, string $period, float $current, int $collectorId, Carbon $date): array
    {
        $result = BillingService::enterMeteredReading($sub, $period, $current, $collectorId, 'Meter read on site');
        $this->touchDates([$result['reading'], $result['bill']], $date);

        return [$result['reading'], $result['bill']];
    }

    private function collectRaw(Subscriber $sub, float $amount, int $collectorId, string $note, ?string $period, Carbon $date, string $method = 'cash_usd'): array
    {
        // The office raises the month's invoice first, then takes the money: a collection can no
        // longer issue one (see BillingService::collect). A flat-rate demo row paying a cycle that
        // was never billed gets its bill here, dated before the receipt, so the seeded books read
        // like a month's real work instead of money recorded against nothing. The running month's
        // collections name no cycle, so resolve it exactly as collect() does.
        $period ??= BillingService::currentPeriod();

        if ($sub->isFlat()) {
            $bill = BillingService::ensureFlatBill($sub, $period);
            if ($bill->wasRecentlyCreated) {
                $this->touchDates([$bill], $date->copy()->subDays(2));
            }
        }

        try {
            return BillingService::collect($sub, $amount, $collectorId, $note, $period, $method);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("Seed collect failed for {$sub->name}: {$e->getMessage()}");
        }
    }

    /** @param  Model[]  $models */
    private function touchDates(array $models, Carbon $date): void
    {
        foreach ($models as $m) {
            DB::table($m->getTable())->where('id', $m->id)->update([
                'created_at' => $date, 'updated_at' => $date,
            ]);
        }
    }
}

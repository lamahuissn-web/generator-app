<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An engineering device the office owns — a generator, a pump, a UPS.
 *
 * One procedure backs everything the centre says about it: the hours it has run come from its own logs (the
 * refuelling sheet and the service sheet), so a device with no logs simply has no figures rather than zeroes
 * pretending to be measurements.
 */
class Equipment extends Model
{
    use HasFactory;

    protected $table = 'equipment';

    public const KIND_GENERATOR = 'generator';
    public const KIND_PUMP = 'pump';
    public const KIND_UPS = 'ups';
    public const KIND_OTHER = 'other';

    public const STATUS_WORKING = 'working';
    public const STATUS_SERVICE = 'service';
    public const STATUS_STOPPED = 'stopped';

    protected $fillable = [
        'name', 'kind', 'capacity', 'serial', 'installed_on',
        'engine_hours_opening', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'installed_on' => 'date',
            'engine_hours_opening' => 'float',
        ];
    }

    public function refuellings(): HasMany
    {
        return $this->hasMany(Refuelling::class)->orderBy('date');
    }

    public function services(): HasMany
    {
        return $this->hasMany(EquipmentService::class)->orderBy('date');
    }

    public static function kinds(): array
    {
        return [
            self::KIND_GENERATOR => __('eq.kind_generator'),
            self::KIND_PUMP => __('eq.kind_pump'),
            self::KIND_UPS => __('eq.kind_ups'),
            self::KIND_OTHER => __('eq.kind_other'),
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_WORKING => __('eq.status_working'),
            self::STATUS_SERVICE => __('eq.status_service'),
            self::STATUS_STOPPED => __('eq.status_stopped'),
        ];
    }

    public function kindLabel(): string
    {
        return self::kinds()[$this->kind] ?? (string) $this->kind;
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? (string) $this->status;
    }

    public function isWorking(): bool
    {
        return $this->status === self::STATUS_WORKING;
    }

    /** The engine-hour reading on the clock now: the newest figure any of its own logs carries. */
    public function currentEngineHours(): ?float
    {
        $fromFuel = $this->refuellings()->whereNotNull('engine_hours')->max('engine_hours');
        $fromService = $this->services()->whereNotNull('engine_hours')->max('engine_hours');

        $latest = array_filter([$fromFuel === null ? null : (float) $fromFuel, $fromService === null ? null : (float) $fromService]);

        if ($latest === []) {
            // No log carries hours: the opening figure is all we honestly know.
            return (float) $this->engine_hours_opening > 0 ? (float) $this->engine_hours_opening : null;
        }

        return max($latest);
    }
}

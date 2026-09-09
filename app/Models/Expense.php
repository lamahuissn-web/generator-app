<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public const CAT_FUEL = 'fuel';

    public const CAT_OIL = 'oil';

    public const CAT_REPAIR = 'repair';

    public const CAT_SALARY = 'salary';

    public const CAT_RENT = 'rent';

    public const CAT_UTILITIES = 'utilities';

    public const CAT_OTHER = 'other';

    public static array $categories = [
        self::CAT_FUEL => 'Fuel / Diesel',
        self::CAT_OIL => 'Oil & filters',
        self::CAT_REPAIR => 'Repairs / maintenance',
        self::CAT_SALARY => 'Salaries',
        self::CAT_RENT => 'Site rent',
        self::CAT_UTILITIES => 'Utilities',
        self::CAT_OTHER => 'Other',
    ];

    protected $fillable = [
        'category', 'description', 'amount', 'quantity', 'expense_date', 'receipt_path', 'entered_by',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
        ];
    }

    public static function label(string $category): string
    {
        return __("exp.{$category}");
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}

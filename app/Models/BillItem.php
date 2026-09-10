<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a special invoice: description + quantity × unit price. */
class BillItem extends Model
{
    protected $fillable = ['bill_id', 'description', 'quantity', 'unit_price', 'amount', 'sort'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price' => 'float',
            'amount' => 'float',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** Items the operator picks from; free text is always allowed too. */
    public static array $presets = [
        'قطع غيار / عدّاد جديد',
        'أجور فنّي / تركيبة',
        'رسوم توصيل / كوابل',
        'غرامة / تأخير',
    ];
}

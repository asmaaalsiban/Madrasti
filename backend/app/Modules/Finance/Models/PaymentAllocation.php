<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['payment_id', 'installment_id', 'amount'];
}

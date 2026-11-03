<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A purchase request: pending → approved (or rejected) → purchased → received. */
class ProcurementRequest extends Model
{
    use Auditable;

    public const CATEGORIES = ['stationery', 'teaching_aids', 'equipment', 'maintenance', 'cleaning', 'it', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'requested_by', 'title', 'description', 'quantity', 'category', 'priority', 'estimated_cost', 'needed_by', 'status', 'reviewed_by', 'reviewed_at', 'review_note',
        'supplier', 'actual_cost', 'purchase_ref', 'purchased_by', 'purchased_at', 'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['estimated_cost' => 'decimal:2', 'actual_cost' => 'decimal:2', 'needed_by' => 'date:Y-m-d', 'reviewed_at' => 'datetime', 'purchased_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchased_by');
    }
}

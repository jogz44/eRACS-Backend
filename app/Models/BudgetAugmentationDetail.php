<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\TranAppropriation;

class BudgetAugmentationDetail extends Model
{
    protected $fillable = [
        'budget_augmentation_id',
        'from_appropriation_id',
        'to_appropriation_id',
        'amount',
        'particulars'
    ];

    protected $casts = [
        'amount' => 'decimal:2'
    ];

    public function budgetAugmentation(): BelongsTo
    {
        return $this->belongsTo(BudgetAugmentation::class);
    }

    public function fromAppropriation(): BelongsTo
    {
        return $this->belongsTo(TranAppropriation::class, 'from_appropriation_id');
    }

    public function toAppropriation(): BelongsTo
    {
        return $this->belongsTo(TranAppropriation::class, 'to_appropriation_id');
    }
} 
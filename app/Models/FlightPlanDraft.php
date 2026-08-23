<?php

namespace App\Models;

use App\DataObjects\FlightPlanFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The flight plan someone is in the middle of writing.
 *
 * @property int $id
 * @property string $phone
 * @property string $status
 * @property array<string, mixed> $data
 * @property array<int, string>|null $auto
 * @property array<int, string>|null $asked
 * @property string|null $flow_token
 * @property int|null $source_plan_id
 * @property Carbon $expires_at
 */
class FlightPlanDraft extends Model
{
    /** Still being filled in. */
    public const COLLECTING = 'collecting';

    /** Complete, waiting for the pilot to confirm it. */
    public const READY = 'ready';

    /** Rendered and sent. Kept a while so a late correction still lands here. */
    public const ISSUED = 'issued';

    protected $fillable = [
        'phone',
        'status',
        'data',
        'auto',
        'asked',
        'flow_token',
        'source_plan_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'auto' => 'array',
            'asked' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function fields(): FlightPlanFields
    {
        return FlightPlanFields::fromStorage($this->data);
    }

    /**
     * Whether the bot worked $field out rather than being told it.
     *
     * The one question that stops a hand-corrected EET from being silently
     * recalculated on the next message.
     */
    public function wasComputed(string $field): bool
    {
        return in_array($field, $this->auto ?? [], true);
    }

    /**
     * @return BelongsTo<FlightPlan, $this>
     */
    public function sourcePlan(): BelongsTo
    {
        return $this->belongsTo(FlightPlan::class, 'source_plan_id');
    }

    /**
     * @param  Builder<FlightPlanDraft>  $query
     * @return Builder<FlightPlanDraft>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<FlightPlanDraft>  $query
     * @return Builder<FlightPlanDraft>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }
}

<?php

namespace App\Models;

use App\DataObjects\FlightPlanFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A flight plan the bot has produced, and the document it produced.
 *
 * @property int $id
 * @property string $phone
 * @property array<string, mixed> $data
 * @property string|null $departure_anac
 * @property string|null $departure_icao
 * @property string|null $destination_anac
 * @property string|null $destination_icao
 * @property Carbon|null $departure_at
 * @property string $pdf_disk
 * @property string|null $pdf_path
 * @property string $pdf_token
 * @property Carbon $pdf_expires_at
 */
class FlightPlan extends Model
{
    protected $fillable = [
        'phone',
        'data',
        'departure_anac',
        'departure_icao',
        'destination_anac',
        'destination_icao',
        'departure_at',
        'pdf_disk',
        'pdf_path',
        'pdf_token',
        'pdf_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'departure_at' => 'datetime',
            'pdf_expires_at' => 'datetime',
        ];
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    public function fields(): FlightPlanFields
    {
        return FlightPlanFields::fromStorage($this->data);
    }

    /**
     * What the file is called once it is saved on a phone.
     *
     * The same shape the online form uses, because it is a good one: the
     * registration, the route and the date, so a folder of them sorts and
     * reads without opening any.
     */
    public function filename(): string
    {
        $fields = $this->fields();

        $name = implode('-', array_filter([
            $fields->aircraftIdentification,
            $fields->departureAerodrome.($fields->departureTime === null ? '' : "({$fields->departureTime})"),
            $fields->destinationAerodrome,
        ]));

        $date = $this->departure_at?->format('dmy');

        return Str::of($name === '' ? 'plan-de-vuelo' : $name)
            ->append($date === null ? '' : "_{$date}")
            ->append('.pdf')
            ->replace(' ', '')
            ->toString();
    }

    public function hasDownloadableDocument(): bool
    {
        return $this->pdf_path !== null && $this->pdf_expires_at->isFuture();
    }

    /**
     * @param  Builder<FlightPlan>  $query
     * @return Builder<FlightPlan>
     */
    public function scopeForPhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone);
    }

    /**
     * @param  Builder<FlightPlan>  $query
     * @return Builder<FlightPlan>
     */
    public function scopeMostRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }
}

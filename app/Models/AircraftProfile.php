<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What a registration means, so naming one fills in most of a flight plan.
 *
 * @property int $id
 * @property string $phone
 * @property string $registration
 * @property string|null $aircraft_type
 * @property string|null $wake_turbulence
 * @property string|null $equipment_com
 * @property string|null $equipment_surveillance
 * @property string|null $cruising_speed
 * @property int|null $cruise_tas_kt
 * @property string|null $default_level
 * @property string|null $endurance
 * @property string|null $colour_markings
 * @property array<int, string>|null $emergency_radio
 * @property array<int, string>|null $survival_equipment
 * @property array<int, string>|null $jackets
 * @property array<string, mixed>|null $dinghies
 * @property int $times_used
 * @property Carbon|null $last_used_at
 */
class AircraftProfile extends Model
{
    protected $fillable = [
        'phone',
        'registration',
        'aircraft_type',
        'wake_turbulence',
        'equipment_com',
        'equipment_surveillance',
        'cruising_speed',
        'cruise_tas_kt',
        'default_level',
        'endurance',
        'colour_markings',
        'emergency_radio',
        'survival_equipment',
        'jackets',
        'dinghies',
        'times_used',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'emergency_radio' => 'array',
            'survival_equipment' => 'array',
            'jackets' => 'array',
            'dinghies' => 'array',
            'cruise_tas_kt' => 'integer',
            'times_used' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * A registration as it is matched rather than as it is written: without
     * the hyphen and upper case, so "lv-haw", "LVHAW" and "LV HAW" are one
     * aircraft.
     */
    public static function normalise(string $registration): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $registration));
    }

    /**
     * @param  Builder<AircraftProfile>  $query
     * @return Builder<AircraftProfile>
     */
    public function scopeForPhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone);
    }

    /**
     * @param  Builder<AircraftProfile>  $query
     * @return Builder<AircraftProfile>
     */
    public function scopeMatching(Builder $query, string $registration): Builder
    {
        // Compared normalised on both sides: the column holds whatever the
        // pilot typed, hyphen and all.
        return $query->whereRaw(
            "upper(replace(replace(registration, '-', ''), ' ', '')) = ?",
            [self::normalise($registration)],
        );
    }
}

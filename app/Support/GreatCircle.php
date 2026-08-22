<?php

namespace App\Support;

/**
 * Distance and bearing along the shortest path between two points on Earth.
 *
 * Two things in this application need it and neither is served by the flat
 * approximation already used to pick a nearby weather station: an estimated
 * time en route is a number a pilot writes on a flight plan, and the AIS
 * office a plan gets filed with is chosen by which one is actually closest.
 * Both want real distance, and over the length of Argentina -- some 2,000
 * nautical miles north to south -- flat arithmetic is off by enough to matter.
 *
 * The haversine formula, on a sphere. Not an ellipsoid: the difference is a
 * few tenths of a percent, which disappears long before the ten minutes of
 * climb and descent an EET already rounds by.
 */
final class GreatCircle
{
    /**
     * The Earth's mean radius in nautical miles. A nautical mile is defined as
     * a minute of arc, which is why aviation distance is this and not
     * kilometres.
     */
    private const EARTH_RADIUS_NM = 3440.065;

    public static function distanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_NM * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * The true course to steer on departure, in degrees from true north.
     *
     * Initial, not constant: a great circle changes heading as it goes. Over
     * the legs a light aircraft flies the drift is small, but the name says
     * which one this is so nobody has to guess.
     */
    public static function initialBearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLon = deg2rad($lon2 - $lon1);
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);

        $bearing = rad2deg(atan2(
            sin($dLon) * cos($lat2),
            cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLon),
        ));

        return fmod($bearing + 360.0, 360.0);
    }
}

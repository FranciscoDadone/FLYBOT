<?php

/**
 * Where every value goes on the EANA flight plan form.
 *
 * The form itself is resources/images/fpl/eana-fpl.jpg, a 1240x1754 scan of
 * the blank ICAO/EANA form -- A4 at exactly 150 dpi. Every coordinate below is
 * in the pixels of that image, because pixels are the unit you actually
 * measure in when you open it; FlightPlanPdfRenderer converts to millimetres
 * with a single scale factor, so recalibrating never touches PHP or Blade.
 *
 * The numbers were not estimated by eye. They were read out of a real filled
 * plan with `pdftotext -bbox-layout`, which reports the bounding box of every
 * glyph drawn on the page in PDF points, and confirmed by rasterising that
 * same plan at 150 dpi and diffing it against the blank form -- which shows,
 * pixel for pixel, where each mark lands. `php artisan fpl:sample --diff`
 * repeats that comparison against our own output.
 *
 * `y` is the top of the text line box, not the top of the ink: it is what a
 * positioned box's `top` means, and at font-size 11pt the two differ by about
 * four pixels.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | The canvas
    |--------------------------------------------------------------------------
    */

    'page' => ['width' => 1240, 'height' => 1754],

    /*
    |--------------------------------------------------------------------------
    | Ordinary text fields
    |--------------------------------------------------------------------------
    |
    | `spacing` is letter-spacing in pixels, which the ICAO boxes want because
    | the form expects one character per notional cell even where it prints no
    | cell dividers. `lines` and `leading` belong to the two fields that wrap:
    | the route and the other-information block both continue onto the blank
    | rules underneath their own row.
    |
    */

    'fields' => [

        // Item 7 -- aircraft identification.
        'aircraftIdentification' => ['x' => 425, 'y' => 409, 'w' => 200, 'size' => 11, 'spacing' => 15.7],

        // Item 8 -- flight rules and type of flight, one letter each.
        'flightRules' => ['x' => 839, 'y' => 409, 'w' => 30, 'size' => 11],
        'typeOfFlight' => ['x' => 1058, 'y' => 409, 'w' => 30, 'size' => 11],

        // Item 9 -- number, type, wake turbulence category.
        'number' => ['x' => 155, 'y' => 496, 'w' => 60, 'size' => 11, 'align' => 'center'],
        'typeOfAircraft' => ['x' => 315, 'y' => 496, 'w' => 160, 'size' => 11, 'spacing' => 15.7],
        'wakeTurbulenceCat' => ['x' => 628, 'y' => 496, 'w' => 30, 'size' => 11],

        // Item 10 -- equipment and capabilities, then the surveillance letter
        // after the printed slash.
        'equipmentCom' => ['x' => 836, 'y' => 496, 'w' => 220, 'size' => 11],
        'equipmentSurveillance' => ['x' => 1079, 'y' => 496, 'w' => 90, 'size' => 11],

        // Item 13 -- departure aerodrome and off-block time.
        'departureAerodrome' => ['x' => 350, 'y' => 573, 'w' => 160, 'size' => 11, 'spacing' => 15.7],
        'departureTime' => ['x' => 766, 'y' => 573, 'w' => 130, 'size' => 11, 'spacing' => 15.7],

        // Item 15 -- cruising speed, level, route.
        'cruisingSpeed' => ['x' => 94, 'y' => 660, 'w' => 175, 'size' => 11, 'spacing' => 15.7],
        'level' => ['x' => 373, 'y' => 660, 'w' => 140, 'size' => 11, 'spacing' => 15.7],
        'route' => ['x' => 601, 'y' => 660, 'w' => 590, 'size' => 11, 'lines' => 4, 'leading' => 33],

        // Item 16 -- destination, total EET, alternates.
        'destinationAerodrome' => ['x' => 200, 'y' => 870, 'w' => 160, 'size' => 11, 'spacing' => 15.7],
        'totalEet' => ['x' => 468, 'y' => 870, 'w' => 130, 'size' => 11, 'spacing' => 15.7],
        'alternateAerodrome' => ['x' => 726, 'y' => 870, 'w' => 160, 'size' => 11, 'spacing' => 15.7],
        'alternateAerodrome2' => ['x' => 982, 'y' => 870, 'w' => 160, 'size' => 11, 'spacing' => 15.7],

        // Item 18 -- other information. Wraps onto the two blank rules below.
        'otherInformation' => ['x' => 116, 'y' => 942, 'w' => 1070, 'size' => 11, 'lines' => 3, 'leading' => 36],

        // Item 19 -- supplementary information, not transmitted in the FPL
        // message but printed on the form the ARO/AIS receives.
        'endurance' => ['x' => 129, 'y' => 1171, 'w' => 130, 'size' => 11, 'spacing' => 15.7],
        'personsOnBoard' => ['x' => 453, 'y' => 1171, 'w' => 90, 'size' => 11],
        'dinghiesNumber' => ['x' => 155, 'y' => 1381, 'w' => 65, 'size' => 11, 'align' => 'center'],
        'dinghiesCapacity' => ['x' => 300, 'y' => 1381, 'w' => 145, 'size' => 11, 'align' => 'center'],
        'dinghiesColour' => ['x' => 680, 'y' => 1381, 'w' => 330, 'size' => 11],
        'aircraftColourAndMarkings' => ['x' => 153, 'y' => 1456, 'w' => 1000, 'size' => 11],
        'remarks' => ['x' => 153, 'y' => 1520, 'w' => 880, 'size' => 11],
        'pilotInCommand' => ['x' => 153, 'y' => 1584, 'w' => 660, 'size' => 11],

        // Filed by, on the strip under the form.
        'filedBy' => ['x' => 57, 'y' => 1630, 'w' => 380, 'size' => 11],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cell boxes
    |--------------------------------------------------------------------------
    |
    | The header prints tick marks: one character per cell, and a proportional
    | font drifts out of them within four or five characters. These are placed
    | a character at a time instead.
    |
    */

    'combs' => [
        'filingTime' => ['x' => 168, 'y' => 288, 'pitch' => 30.5, 'cells' => 4, 'size' => 11],
        'originator' => ['x' => 790, 'y' => 288, 'pitch' => 30.5, 'cells' => 8, 'size' => 11],
    ],

    /*
    |--------------------------------------------------------------------------
    | Struck-out boxes
    |--------------------------------------------------------------------------
    |
    | The letters of item 19 are preprinted on the form: U V E for the radios,
    | S P D M J for survival equipment, J L F U V for jackets, D for dinghies
    | and C for their cover, N for remarks. What gets marked is what is NOT
    | carried, so the reader is left with the letters that are.
    |
    | A struck box is filled solid, not crossed out -- which is what the form
    | itself does and what an ARO/AIS reads at a glance. Every box on the page
    | is the same 39x33, so the size lives on the group and only the left edge
    | of each cell varies.
    |
    */

    'strikes' => [
        'emergencyRadio' => [
            'y' => 1168, 'w' => 39, 'h' => 33,
            'cells' => ['U' => 895, 'V' => 969, 'E' => 1041],
        ],
        'survivalEquipment' => [
            'y' => 1273, 'w' => 39, 'h' => 33,
            'cells' => ['S' => 148, 'P' => 222, 'D' => 298, 'M' => 371, 'J' => 441],
        ],
        'jackets' => [
            'y' => 1274, 'w' => 39, 'h' => 33,
            'cells' => ['J' => 601, 'L' => 712, 'F' => 823, 'U' => 935, 'V' => 1046],
        ],
        'dinghies' => [
            'y' => 1378, 'w' => 39, 'h' => 33,
            'cells' => ['D' => 76, 'C' => 525],
        ],
        'remarks' => [
            'y' => 1517, 'w' => 39, 'h' => 33,
            'cells' => ['N' => 76],
        ],
    ],
];

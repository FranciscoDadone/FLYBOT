<?php

namespace App\Console\Commands;

use App\DataObjects\FlightPlanFields;
use App\Services\FlightPlanPdfRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a known flight plan so the form's coordinates can be checked by eye.
 *
 * Calibrating a scanned form is the one part of this that cannot be reasoned
 * out: a box is where it is. --grid draws a ruler and every field's name in
 * its own position over the blank form, which turns "the level is a bit high"
 * into a number to change in resources/data/fpl-layout.php.
 *
 * Local only, like the bot's test endpoint: it writes files and exists to be
 * looked at.
 */
class RenderFlightPlanSample extends Command
{
    protected $signature = 'fpl:sample {--grid : Draw a pixel ruler and field names instead of a filled plan}';

    protected $description = 'Render a sample flight plan PDF to check the form layout';

    public function handle(FlightPlanPdfRenderer $renderer): int
    {
        // Nothing here is dangerous, but it writes files nobody asked for and
        // its whole purpose is to be opened and looked at. Same reasoning as
        // the bot's test endpoint in routes/api.php.
        if (! app()->environment('local')) {
            $this->error('fpl:sample es una herramienta de calibración: sólo corre en local.');

            return self::FAILURE;
        }

        $path = config('fpl.directory').'/sample'.($this->option('grid') ? '-grid' : '').'.pdf';

        $pdf = $this->option('grid')
            ? $renderer->renderCalibrationGrid()
            : $renderer->render($this->sample());

        Storage::disk(config('fpl.disk'))->put($path, $pdf);

        $this->info('Escrito en '.Storage::disk(config('fpl.disk'))->path($path));

        foreach ($renderer->overflowed() as $field) {
            $this->warn("El campo {$field} no entra en su casilla.");
        }

        return self::SUCCESS;
    }

    /**
     * The plan the layout was measured from, so a render can be compared
     * against the original page it came out of.
     */
    protected function sample(): FlightPlanFields
    {
        return new FlightPlanFields(
            aircraftIdentification: 'LVHAW',
            flightRules: 'I',
            typeOfFlight: 'G',
            typeOfAircraft: 'C208',
            wakeTurbulenceCat: 'L',
            equipmentCom: 'SDG',
            equipmentSurveillance: 'C',
            departureAerodrome: 'SADF',
            departureTime: '1945',
            cruisingSpeed: 'N0175',
            level: 'F100',
            route: 'DCT ASADA W68 IRASU W73 TIMKU OSA',
            destinationAerodrome: 'SAZR',
            totalEet: '0220',
            alternateAerodrome: 'SAZR',
            otherInformation: 'DOF/260704 OPR/JAVIERCATONI PER/B',
            endurance: '0500',
            personsOnBoard: '1/2',
            emergencyRadio: ['V', 'E'],
            aircraftColourAndMarkings: 'BLANCO CON AZUL',
            pilotInCommand: 'NOMBRE APELLIDO PCA123',
            filedBy: 'NOMBRE APELLIDO PCA123',
        );
    }
}

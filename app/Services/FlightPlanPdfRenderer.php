<?php

namespace App\Services;

use App\DataObjects\FlightPlanFields;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Draws a filled ICAO flight plan onto the blank EANA form and returns a PDF.
 *
 * The form is a 1240x1754 scan -- A4 at exactly 150 dpi -- and every value is
 * a positioned box on top of it, which is how the online form pilots use today
 * does it too (its output is mPDF over the same image). Reproducing the method
 * rather than the site means nothing here depends on a third party staying up,
 * and the layout lives in a data file that can be recalibrated without
 * touching code.
 *
 * The one thing this class insists on is that a value never silently loses
 * characters: a route that does not fit its four rules is reported to the
 * caller rather than clipped, because a pilot cannot see what the renderer
 * dropped.
 */
class FlightPlanPdfRenderer
{
    /**
     * A PostScript point in millimetres. Font sizes are given in points and
     * every coordinate here is in millimetres, so this appears wherever the
     * two meet.
     */
    protected const MM_PER_PT = 25.4 / 72;

    /**
     * Millimetres per pixel of the form scan, one factor per axis. They differ
     * in the sixth decimal and are kept apart anyway: the day someone replaces
     * the image with one that is not exactly A4, this is where it shows.
     */
    protected float $mmX;

    protected float $mmY;

    /** @var array<string, mixed> */
    protected array $layout;

    /**
     * Fields whose value overflowed the space the form gives it, from the last
     * render. Empty when everything fitted.
     *
     * @var array<int, string>
     */
    protected array $overflowed = [];

    public function __construct()
    {
        $this->layout = require resource_path('data/fpl-layout.php');

        $this->mmX = 210.0 / $this->layout['page']['width'];
        $this->mmY = 297.0 / $this->layout['page']['height'];
    }

    /**
     * The PDF, as bytes.
     */
    public function render(FlightPlanFields $fields): string
    {
        $this->overflowed = [];

        $html = view('fpl.form', [
            'background' => config('fpl.background'),
            'fields' => $this->boxes($fields),
            'strikes' => $this->strikeBoxes($fields),
        ])->render();

        $mpdf = new Mpdf([
            'format' => 'A4',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'margin_header' => 0,
            'margin_footer' => 0,
            'tempDir' => config('fpl.temp_dir'),
            // The background is already 150 dpi at final size; letting mPDF
            // resample it would cost a second of CPU per render and lose the
            // hairlines the form's boxes are drawn with.
            'img_dpi' => 150,
        ]);

        $mpdf->WriteHTML($html);

        return (string) $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * The blank form under a pixel ruler, with every box the layout defines
     * drawn in its place. The tool the coordinates above were tuned with.
     */
    public function renderCalibrationGrid(): string
    {
        $rulers = ['v' => [], 'h' => []];

        for ($px = 0; $px <= $this->layout['page']['width']; $px += 50) {
            $rulers['v'][] = ['px' => $px, 'at' => round($px * $this->mmX, 2)];
        }

        for ($px = 0; $px <= $this->layout['page']['height']; $px += 50) {
            $rulers['h'][] = ['px' => $px, 'at' => round($px * $this->mmY, 2)];
        }

        $fields = [];

        foreach ($this->layout['fields'] as $name => $box) {
            $lines = $box['lines'] ?? 1;
            $leading = $box['leading'] ?? ($box['size'] * 1.164 * self::MM_PER_PT / $this->mmY);

            $fields[$name] = [
                'left' => round($box['x'] * $this->mmX, 2),
                'top' => round($box['y'] * $this->mmY, 2),
                'width' => round($box['w'] * $this->mmX, 2),
                'height' => round($lines * $leading * $this->mmY, 2),
            ];
        }

        $mpdf = new Mpdf([
            'format' => 'A4',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'tempDir' => config('fpl.temp_dir'),
            'img_dpi' => 150,
        ]);

        $mpdf->WriteHTML(view('fpl.grid', [
            'background' => config('fpl.background'),
            'rulers' => $rulers,
            'fields' => $fields,
            'strikes' => $this->strikeBoxes(new FlightPlanFields),
        ])->render());

        return (string) $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * Fields that did not fit, from the last render.
     *
     * @return array<int, string>
     */
    public function overflowed(): array
    {
        return $this->overflowed;
    }

    /**
     * Every text box to draw, already in millimetres.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function boxes(FlightPlanFields $fields): array
    {
        $boxes = [];

        foreach ($this->layout['fields'] as $name => $box) {
            $value = $this->valueFor($fields, $name);

            if ($value === '') {
                continue;
            }

            $lines = $box['lines'] ?? 1;
            $leading = $box['leading'] ?? ($box['size'] * 1.164 * self::MM_PER_PT / $this->mmY);

            if ($this->exceeds($value, $box, $lines)) {
                $this->overflowed[] = $name;
            }

            $boxes[] = [
                'left' => round($box['x'] * $this->mmX, 2),
                'top' => round($box['y'] * $this->mmY, 2),
                'width' => round($box['w'] * $this->mmX, 2),
                'size' => $box['size'],
                'leading' => round($leading * $this->mmY, 2),
                'align' => $box['align'] ?? 'left',
                'spacing' => round(($box['spacing'] ?? 0) * $this->mmX, 2),
                'value' => $value,
            ];
        }

        foreach ($this->layout['combs'] as $name => $comb) {
            $value = $this->valueFor($fields, $name);

            // One character per printed cell: a proportional font drifts out
            // of the ticks within four or five of them.
            foreach (mb_str_split(mb_substr($value, 0, $comb['cells'])) as $i => $char) {
                $boxes[] = [
                    'left' => round(($comb['x'] + $i * $comb['pitch']) * $this->mmX, 2),
                    'top' => round($comb['y'] * $this->mmY, 2),
                    'width' => round($comb['pitch'] * $this->mmX, 2),
                    'size' => $comb['size'],
                    'leading' => round($comb['size'] * 1.164 * self::MM_PER_PT, 2),
                    'align' => 'center',
                    'spacing' => 0,
                    'value' => $char,
                ];
            }
        }

        return $boxes;
    }

    /**
     * The boxes to black out: the equipment of item 19 that is NOT carried.
     *
     * The form preprints every letter, so leaving one alone is what says the
     * aircraft has it. Marking is therefore the negative of the field, which
     * is the opposite of how the value reads and worth stating once here.
     *
     * The mark is a solid fill and not a cross. That is what the paper form
     * does, and it is the difference between a box an ARO/AIS reads as struck
     * at a glance and one that still shows a letter under a line.
     *
     * Public because that inversion is the one rule on this page worth
     * asserting on its own: read wrong, it says an aircraft carries equipment
     * it does not.
     *
     * @return array<int, array<string, mixed>>
     */
    public function strikeBoxes(FlightPlanFields $fields): array
    {
        $hasDinghies = $fields->dinghiesNumber !== null && $fields->dinghiesNumber !== '';

        $carried = [
            'emergencyRadio' => $fields->emergencyRadio ?? [],
            'survivalEquipment' => $fields->survivalEquipment ?? [],
            'jackets' => $fields->jackets ?? [],
            // With no dinghies aboard the whole row is void, and striking the
            // cover as well would be saying something about a boat that is not
            // there. D alone carries it.
            'dinghies' => $hasDinghies
                ? array_filter(['D', $fields->dinghiesCovered === true ? 'C' : null])
                : ['C'],
            'remarks' => $fields->remarks === null || $fields->remarks === '' ? [] : ['N'],
        ];

        $boxes = [];

        foreach ($this->layout['strikes'] as $group => $spec) {
            $have = array_map('strtoupper', $carried[$group] ?? []);

            foreach ($spec['cells'] as $letter => $x) {
                if (in_array($letter, $have, true)) {
                    continue;
                }

                $boxes[] = [
                    'left' => round($x * $this->mmX, 2),
                    'top' => round($spec['y'] * $this->mmY, 2),
                    'width' => round($spec['w'] * $this->mmX, 2),
                    'height' => round($spec['h'] * $this->mmY, 2),
                ];
            }
        }

        return $boxes;
    }

    /**
     * The printable value of one field, uppercased and trimmed.
     *
     * Item 19's dinghy count reads "2" and its cover is a struck box, so the
     * two are handled where they are drawn rather than here; everything else
     * is the property of the same name.
     */
    protected function valueFor(FlightPlanFields $fields, string $name): string
    {
        $value = property_exists($fields, $name) ? $fields->{$name} : null;

        if ($value === null || is_array($value) || is_bool($value)) {
            return '';
        }

        return mb_strtoupper(trim((string) $value));
    }

    /**
     * Whether $value needs more room than the form gives it.
     *
     * Approximate on purpose: DejaVu Serif Condensed averages a shade under
     * half its em, and the point is to notice a route that is going to be
     * clipped, not to predict the break to the character.
     *
     * @param  array<string, mixed>  $box
     */
    protected function exceeds(string $value, array $box, int $lines): bool
    {
        $emPx = $box['size'] * self::MM_PER_PT / $this->mmX;
        $perChar = $emPx * 0.5 + ($box['spacing'] ?? 0);

        return mb_strlen($value) * $perChar > $box['w'] * $lines;
    }
}

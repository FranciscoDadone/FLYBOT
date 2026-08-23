<?php

namespace App\Support;

use App\DataObjects\FlightPlanFields;
use App\Models\FlightPlanDraft;

/**
 * The draft, written out for the pilot to check before it becomes a document.
 *
 * This is the last thing between an extracted message and a form somebody
 * presents to an AIS office, so it is not a summary: everything that will be
 * printed appears here, and everything the bot worked out rather than being
 * told is marked. A value the pilot cannot see is a value they cannot correct.
 *
 * Laid out by item number, in the order they appear on the paper, so that
 * checking the card against a form is reading the same list twice.
 */
class FlightPlanCard
{
    /**
     * What each box is called when the bot has to ask for it, and how the
     * pilot is meant to answer.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PROMPTS = [
        'aircraftIdentification' => ['la *matrícula*', 'LV-HAW'],
        'typeOfAircraft' => ['el *tipo de aeronave* (ítem 9)', 'C208, PA28, C172'],
        'wakeTurbulenceCat' => ['la *categoría de estela* (ítem 9)', 'L, M, H o J'],
        'equipmentCom' => ['el *equipo de comunicación y navegación* (ítem 10)', 'SDG'],
        'equipmentSurveillance' => ['el *equipo de vigilancia* (ítem 10, después de la barra)', 'C — o N si no llevás transpondedor'],
        'flightRules' => ['las *reglas de vuelo* (ítem 8)', 'IFR o VFR'],
        'typeOfFlight' => ['el *tipo de vuelo* (ítem 8)', 'general, regular, militar'],
        'departureAerodrome' => ['el *aeródromo de salida* (ítem 13)', 'San Fernando, SADF'],
        'departureTime' => ['la *hora de salida* (ítem 13)', '19:45 — o 1945Z si me la das en UTC'],
        'destinationAerodrome' => ['el *destino* (ítem 16)', 'Santa Rosa, SAZR'],
        'cruisingSpeed' => ['la *velocidad de crucero* (ítem 15)', '175 kt'],
        'level' => ['el *nivel* (ítem 15)', 'FL100, 4500 pies, VFR'],
        'route' => ['la *ruta* (ítem 15)', 'DCT, o las aerovías'],
        'totalEet' => ['el *EET total* (ítem 16)', '2:20'],
        'endurance' => ['la *autonomía* (ítem 19)', '5:00'],
        'personsOnBoard' => ['las *personas a bordo* (ítem 19)', '2, o 1/2'],
        'aircraftColourAndMarkings' => ['el *color y las marcas* (ítem 19)', 'blanco con azul'],
        'pilotInCommand' => ['el *comandante y su licencia* (ítem 19)', 'Nombre Apellido PCA123'],
    ];

    /**
     * The boxes worth asking for in one question, and how to ask for them.
     *
     * One at a time is right when the answers have nothing to do with each
     * other and wrong when they are one thought. Nobody works out a cruising
     * speed without knowing the level they are going to fly it at, and asking
     * for "175 kt", then "FL100", then "DCT" is three round trips for a
     * sentence the pilot would have written in one go.
     *
     * Only boxes the parser can tell apart inside a single answer go in a
     * group. A group whose answer had to be split by position would put the
     * level into the speed the first time somebody answers them the other way
     * round, and a wrong value that looks right is the failure this whole
     * feature is built around avoiding.
     *
     * @var array<int, array{fields: array<int, string>, what: string, example: string}>
     */
    public const GROUPS = [
        [
            'fields' => ['typeOfAircraft', 'wakeTurbulenceCat'],
            'what' => 'el *tipo de aeronave* y la *categoría de estela* (ítem 9)',
            'example' => 'C208, L — o C208, L, SDG/C si me pasás también el equipo',
        ],
        [
            'fields' => ['cruisingSpeed', 'level', 'route'],
            'what' => 'la *velocidad*, el *nivel* y la *ruta* (ítem 15)',
            'example' => '175 kt, FL100, DCT',
        ],
        [
            'fields' => ['personsOnBoard', 'endurance'],
            'what' => 'las *personas a bordo* y la *autonomía* (ítem 19)',
            'example' => '2 personas, autonomía 5:00',
        ],
    ];

    public function __construct(protected AirportResolver $airports) {}

    /**
     * The whole draft as one message body.
     */
    public function render(FlightPlanDraft $draft): string
    {
        $f = $draft->fields();

        // A label per row rather than an icon. The two are the same length on
        // screen and only one of them says what it is: a 🛫 next to a 🛬 is a
        // guess, "Salida" and "Destino" are not -- and the whole point of the
        // card is that a pilot can check it at a glance.
        $rows = [
            'Aeronave' => $this->aircraft($f),
            'Reglas' => $this->rules($f),
            'Salida' => $this->departure($f),
            'Crucero' => $this->cruise($f),
            'Destino' => $this->arrival($f, $draft),
            'Alternativa' => $f->alternateAerodrome === null
                ? null
                : $this->place($f->alternateAerodrome, $f->alternateAnacCode),
            'A bordo' => $this->onBoard($f, $draft),
            'Color y marcas' => $f->aircraftColourAndMarkings ?? $this->pending(),
            'Comandante' => $f->pilotInCommand ?? $this->pending(),
            'Otros datos' => $f->otherInformation,
        ];

        $lines = [
            $draft->status === FlightPlanDraft::ISSUED
                ? '✈️ *Plan de vuelo* — ya generado'
                : '✈️ *Plan de vuelo* — borrador',
            '',
        ];

        foreach ($rows as $label => $value) {
            // A row with nothing behind it and nothing to ask for -- the
            // alternate, item 18 before there is a date -- is left out rather
            // than shown empty.
            if ($value !== null) {
                $lines[] = "*{$label}:* {$value}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The next question, and what is queued behind it.
     *
     * Never the whole checklist: a list of nine things to answer on WhatsApp is
     * a list nobody answers. Either one box, or the two or three of a group
     * that are one thought. The count of what is left is mentioned so the pilot
     * knows how far off the end is.
     *
     * @param  array<int, string>  $missing
     */
    public function question(array $missing): ?string
    {
        $field = $missing[0] ?? null;

        if ($field === null) {
            return null;
        }

        $group = $this->matchingGroup($missing);

        if ($group !== null) {
            return $this->ask($group['what'], $group['example'], count($missing) - count($group['fields']));
        }

        if (! isset(self::PROMPTS[$field])) {
            return null;
        }

        [$what, $example] = self::PROMPTS[$field];

        return $this->ask($what, $example, count($missing) - 1);
    }

    /**
     * What is still missing, named, for a message that hands over the whole
     * form at once instead of asking one box at a time.
     *
     * Cut off after three. The form itself shows every empty box, so this is
     * only there to say how much is left before the pilot opens it -- and a
     * list of eighteen things would read as a refusal rather than as a
     * summary.
     *
     * @param  array<int, string>  $missing
     */
    public function shortfall(array $missing): string
    {
        $named = array_values(array_filter(array_map(
            fn (string $field) => self::PROMPTS[$field][0] ?? null,
            $missing,
        )));

        $shown = array_slice($named, 0, 3);
        $rest = count($named) - count($shown);

        return implode(', ', $shown).($rest > 0 ? " y {$rest} más" : '');
    }

    /**
     * The boxes the next question covers, or none when it covers only the
     * first. What reads the answer needs to know which question was asked, and
     * this is the same reckoning question() makes.
     *
     * @param  array<int, string>  $missing
     * @return array<int, string>
     */
    public function groupFor(array $missing): array
    {
        return $this->matchingGroup($missing)['fields'] ?? [];
    }

    /**
     * The group the next box belongs to, cut down to the members that are
     * actually still empty -- and null when that leaves only one, because a
     * group of one is a plain question.
     *
     * @param  array<int, string>  $missing
     * @return array{fields: array<int, string>, what: string, example: string}|null
     */
    protected function matchingGroup(array $missing): ?array
    {
        $field = $missing[0] ?? null;

        if ($field === null) {
            return null;
        }

        foreach (self::GROUPS as $group) {
            if (! in_array($field, $group['fields'], true)) {
                continue;
            }

            // Intersecting this way round keeps the group's own order, which is
            // the order the question names them in and the order the answer is
            // read in.
            $group['fields'] = array_values(array_intersect($group['fields'], $missing));

            return count($group['fields']) > 1 ? $group : null;
        }

        return null;
    }

    protected function ask(string $what, string $example, int $remaining): string
    {
        $tail = match (true) {
            $remaining <= 0 => 'Es lo último que me falta.',
            $remaining === 1 => 'Después me falta un dato más.',
            default => "Después me faltan {$remaining} datos más.",
        };

        return "Necesito {$what}.\n_Por ejemplo: {$example}_\n\n{$tail}";
    }

    protected function aircraft(FlightPlanFields $f): string
    {
        $type = implode('/', array_filter([$f->typeOfAircraft, $f->wakeTurbulenceCat]));
        $equipment = implode('/', array_filter([$f->equipmentCom, $f->equipmentSurveillance]));

        return $this->join([
            $f->aircraftIdentification ?? $this->pending(),
            $type === '' ? $this->pending() : $type,
            $equipment === '' ? $this->pending() : $equipment,
        ]);
    }

    protected function rules(FlightPlanFields $f): string
    {
        return $this->join([
            match ($f->flightRules) {
                'I' => 'IFR', 'V' => 'VFR', 'Y' => 'IFR luego VFR', 'Z' => 'VFR luego IFR',
                default => $this->pending(),
            },
            match ($f->typeOfFlight) {
                'G' => 'aviación general', 'S' => 'regular', 'N' => 'no regular',
                'M' => 'militar', 'X' => 'otro', default => $this->pending(),
            },
        ]);
    }

    protected function departure(FlightPlanFields $f): string
    {
        $place = $this->place($f->departureAerodrome, $f->departureAnacCode);

        if ($f->departureTime === null) {
            return $place.' · '.$this->pending();
        }

        // Both clocks, always. Item 13 is UTC and a pilot thinks in local time,
        // and the whole point of showing the card is that the pilot can see the
        // one thing most likely to be wrong.
        $local = $f->departureAt?->setTimezone((string) config('app.display_timezone'));

        return $place.' · '.$f->departureTime.' UTC'
            .($local === null ? '' : ' _('.$local->format('H:i').' local del '.$local->format('d/m').')_');
    }

    protected function cruise(FlightPlanFields $f): string
    {
        return $this->join([
            $f->cruisingSpeed ?? $this->pending(),
            $f->level ?? $this->pending(),
            $f->route ?? $this->pending(),
        ]);
    }

    protected function arrival(FlightPlanFields $f, FlightPlanDraft $draft): string
    {
        $eet = 'EET '.($f->totalEet ?? $this->pending());

        if ($draft->wasComputed('totalEet')) {
            $eet .= ' _(estimado: línea recta y sin viento)_';
        }

        return $this->place($f->destinationAerodrome, $f->destinationAnacCode).' · '.$eet;
    }

    protected function onBoard(FlightPlanFields $f, FlightPlanDraft $draft): string
    {
        $endurance = $f->endurance === null ? $this->pending() : 'autonomía '.$f->endurance;

        if ($draft->wasComputed('endurance')) {
            $endurance .= ' _(sugerida)_';
        }

        $radio = $f->emergencyRadio === null || $f->emergencyRadio === []
            ? null
            : implode(' + ', array_map(
                fn (string $l) => ['U' => 'UHF', 'V' => 'VHF', 'E' => 'ELT'][$l] ?? $l,
                $f->emergencyRadio,
            ));

        return $this->join(array_filter([
            $f->personsOnBoard === null ? $this->pending() : $f->personsOnBoard.' personas',
            $endurance,
            $radio,
        ]));
    }

    /**
     * An aerodrome as the pilot recognises it: the indicator that goes on the
     * form, and the name that says whether it is the right one.
     */
    protected function place(?string $indicator, ?string $anacCode): string
    {
        if ($indicator === null) {
            return $this->pending();
        }

        $name = $anacCode === null ? null : $this->airports->nameFor($anacCode);

        // ZZZZ says nothing on its own, so the name carries the whole answer.
        $shown = $indicator === 'ZZZZ' ? ($anacCode ?? 'ZZZZ') : $indicator;

        return $shown.($name === null ? '' : ' '.$name);
    }

    /**
     * A box with nothing in it yet, said in words: a lone question mark reads
     * as a doubt about the value rather than as an absence of one.
     */
    protected function pending(): string
    {
        return '_falta_';
    }

    /**
     * @param  array<int, string>  $parts
     */
    protected function join(array $parts): string
    {
        return implode(' · ', array_filter($parts, fn (string $p) => $p !== ''));
    }
}

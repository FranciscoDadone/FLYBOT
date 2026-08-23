<?php

namespace App\Support;

use App\DataObjects\FlightPlanFields;
use App\DataObjects\ReplyButton;
use Carbon\CarbonImmutable;

/**
 * The flight plan as a form inside WhatsApp, rather than as a conversation.
 *
 * A Flow is a document of screens that Meta hosts: it is published once and
 * from then on a message only says which form, which screen, and what to put
 * in it. The pilot taps, fills the boxes in one sitting with the keyboard and
 * the dropdowns their phone already draws, and everything comes back at once.
 *
 * That is a different bargain from the questions this replaces. What is gained
 * is the whole form at once, visible together, with what the bot already knows
 * already in the boxes -- so a plan is corrected rather than dictated, and the
 * same form serves "fill this in" and "fix that". What is lost is the round
 * trip: an endpointless Flow talks to nobody while it is open, so an aerodrome
 * it cannot resolve is not caught in the form but in the chat afterwards, and
 * a pilot mid-form has no button to get out with. Both are handled where they
 * land -- FlightPlanBotService says which aerodrome it could not place, and
 * "cancelar" typed in the chat still ends the draft.
 *
 * One class for the whole form on purpose. The screens, what is drawn on them
 * and how the submission is read back are three views of one table, and split
 * across files they would drift -- a renamed box that stops arriving is not an
 * error anywhere, it is a question the pilot gets asked again for no reason.
 */
class FlightPlanFlow
{
    /**
     * Where the form opens. The rest of the screens are reached from it.
     */
    public const FIRST_SCREEN = 'AERONAVE';

    /**
     * Meta's own caps on what a component will render. Past them the form is
     * rejected at publish time, which is late: the test holds these instead.
     */
    public const MAX_LABEL = 20;

    public const MAX_HELPER = 80;

    public const MAX_OPTION_TITLE = 30;

    /**
     * The form, screen by screen, in the order the paper is laid out.
     *
     * Grouped by item number rather than by how much fits, because that is how
     * a pilot reads the form they are copying it onto -- and it is the same
     * grouping the card prints, so checking one against the other is reading
     * the same list twice.
     *
     * @var array<string, array{title: string, caption: string, fields: array<int, string>, next: string}>
     */
    protected const SCREENS = [
        'AERONAVE' => [
            'title' => 'La aeronave',
            'caption' => 'Ítems 7, 9 y 10 del formulario.',
            'fields' => ['aircraftIdentification', 'typeOfAircraft', 'wakeTurbulenceCat', 'equipmentCom', 'equipmentSurveillance', 'equipmentOther'],
            'next' => 'Seguir',
        ],
        'VUELO' => [
            'title' => 'El vuelo',
            'caption' => 'Ítems 8, 13 y 16. La hora va en local: yo la paso a UTC.',
            'fields' => ['flightRules', 'typeOfFlight', 'departureAerodrome', 'departureDate', 'departureTime', 'destinationAerodrome', 'alternateAerodrome'],
            'next' => 'Seguir',
        ],
        'CRUCERO' => [
            'title' => 'El crucero',
            'caption' => 'Ítem 15, y el tiempo estimado del ítem 16.',
            'fields' => ['cruisingSpeed', 'level', 'route', 'totalEet'],
            'next' => 'Seguir',
        ],
        'ABORDO' => [
            'title' => 'A bordo',
            'caption' => 'Ítem 19: es lo que se usa para buscarte si algo pasa.',
            'fields' => ['personsOnBoard', 'endurance', 'aircraftColourAndMarkings', 'pilotInCommand'],
            'next' => 'Listo',
        ],
    ];

    /**
     * Every box on the form: how it is drawn, what it is called, the line
     * under it, and an example for Meta's validator.
     *
     * The names are the plan's own, so a submission arrives keyed the way
     * FlightPlanFields already is and nothing has to be translated. The two
     * that are not boxes of the plan -- the date, and the escape hatch for an
     * item 10 the dropdown does not cover -- are read separately below.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    protected const FIELDS = [
        'aircraftIdentification' => ['text', 'Matrícula', 'Como figura en el certificado. Ej: LV-HAW', 'LV-HAW'],
        'typeOfAircraft' => ['text', 'Tipo de aeronave', 'Designador OACI de tres o cuatro letras. Ej: C208, PA28', 'C208'],
        'wakeTurbulenceCat' => ['radio', 'Categoría de estela', '', 'L'],
        'equipmentCom' => ['dropdown', 'Equipo COM/NAV', '', 'SDG'],
        'equipmentSurveillance' => ['dropdown', 'Equipo de vigilancia', '', 'C'],
        'equipmentOther' => ['text', 'Otro equipo', 'Sólo si elegiste "Otro" arriba. Como va en el ítem 10: SDFGRY/S', 'SDG/C'],
        'flightRules' => ['dropdown', 'Reglas de vuelo', '', 'V'],
        'typeOfFlight' => ['dropdown', 'Tipo de vuelo', '', 'G'],
        'departureAerodrome' => ['text', 'Aeródromo de salida', 'Nombre o código. Ej: San Fernando, SADF', 'SADF'],
        'departureDate' => ['date', 'Fecha de salida', '', '2026-08-22'],
        'departureTime' => ['text', 'Hora local de salida', 'La hora del reloj de tu aeródromo. Ej: 19:45', '19:45'],
        'destinationAerodrome' => ['text', 'Destino', 'Nombre o código. Ej: Santa Rosa, SAZR', 'SAZR'],
        'alternateAerodrome' => ['text', 'Alternativa', 'Opcional: adónde irías si no podés aterrizar en el destino', 'SAZM'],
        'cruisingSpeed' => ['text', 'Velocidad de crucero', 'En nudos. Ej: 175 kt', '175 kt'],
        'level' => ['text', 'Nivel', 'Ej: FL100, 4500 pies, VFR', 'FL100'],
        'route' => ['text', 'Ruta', 'DCT si vas directo, o las aerovías', 'DCT'],
        'totalEet' => ['text', 'EET total', 'Cuánto dura el vuelo, en horas y minutos. Ej: 2:20', '2:20'],
        'personsOnBoard' => ['text', 'Personas a bordo', 'Contándote. Ej: 2 — o 1/2 si separás tripulación de pasajeros', '2'],
        'endurance' => ['text', 'Autonomía', 'Combustible a bordo, en horas y minutos. Ej: 5:00', '5:00'],
        'aircraftColourAndMarkings' => ['text', 'Color y marcas', 'Ej: blanco con azul', 'BLANCO CON AZUL'],
        'pilotInCommand' => ['text', 'Comandante', 'Nombre y licencia. Ej: Juan Pérez PCA123', 'Juan Pérez PCA123'],
    ];

    /**
     * The boxes the form will not let go without.
     *
     * Everything a plan cannot be filed without, less the two the bot works
     * out on its own: the EET, which it estimates, and the type of flight,
     * which is general aviation unless somebody says otherwise. Making the
     * pilot answer either of those would be blocking a form on a question the
     * bot has already answered. The alternate is not required anywhere -- a
     * daylight VFR hop does not need one.
     *
     * @var array<int, string>
     */
    protected const NOT_REQUIRED = ['totalEet', 'typeOfFlight'];

    /**
     * The dropdown row that means "none of these": item 10 runs to about
     * thirty letters and the four on offer are the ones general aviation
     * flies, so there has to be somewhere to put an aircraft that carries
     * something else.
     *
     * Only the form has it. A tapped list row in the chat writes its value
     * straight into the plan, and "OTRO" is not a value -- there the pilot
     * types the equipment instead, which the chat has always allowed.
     */
    protected const OTHER_EQUIPMENT = 'OTRO';

    /**
     * The two keys that are not boxes: the bounds on the date picker. They
     * travel as data because the form is published once and today moves.
     *
     * @var array<int, string>
     */
    protected const BOUNDS = ['minDate', 'maxDate'];

    /**
     * How far ahead the picker will let a plan be filed. A plan is a document
     * about a flight in the next few hours; a date a year out is a typo.
     */
    protected const MAX_DAYS_AHEAD = 30;

    public function __construct(protected FlightPlanTextParser $parser) {}

    /**
     * The whole Flow JSON document, ready to be published.
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        $ids = array_keys(self::SCREENS);

        return [
            'version' => (string) config('fpl.flow.json_version'),
            'screens' => array_map(
                fn (int $i) => $this->screen($ids[$i], $ids[$i + 1] ?? null, $i),
                array_keys($ids),
            ),
        ];
    }

    /**
     * What the form opens filled in with.
     *
     * Every box, whether or not it is known: the document is static and each
     * component names the data key it starts from, so a key that is sometimes
     * absent is a form that sometimes fails to open. Unknown is the empty
     * string, which draws as an empty box.
     *
     * The date is the exception and is never sent empty. A picker has no empty
     * state worth the name, and the day a plan is filed for is today far more
     * often than it is anything else -- the card prints the resulting date in
     * both clocks afterwards, which is where a wrong one gets caught.
     *
     * @return array<string, string>
     */
    public function prefill(FlightPlanFields $fields): array
    {
        $local = CarbonImmutable::now((string) config('app.display_timezone'));

        $data = [
            'minDate' => $local->format('Y-m-d'),
            'maxDate' => $local->addDays(self::MAX_DAYS_AHEAD)->format('Y-m-d'),
        ];

        foreach (array_keys(self::FIELDS) as $field) {
            $data[$field] = match ($field) {
                'departureDate' => ($fields->departureAt?->setTimezone((string) config('app.display_timezone')) ?? $local)->format('Y-m-d'),
                'departureTime' => $this->localClock($fields),
                'equipmentCom', 'equipmentSurveillance', 'equipmentOther' => '',
                default => (string) ($fields->{$field} ?? ''),
            };
        }

        return [...$data, ...$this->equipmentPrefill($fields)];
    }

    /**
     * A submission, as the boxes of a flight plan.
     *
     * The departure date and time are not among them: what the form returns is
     * a local clock and a calendar day, and turning those into the UTC instant
     * item 13 wants is the calculator's job, not this one's.
     *
     * @param  array<string, mixed>  $response
     */
    public function read(array $response): FlightPlanFields
    {
        $values = [];

        foreach (self::FIELDS as $field => $spec) {
            if (in_array($field, ['departureDate', 'departureTime', 'equipmentOther'], true)) {
                continue;
            }

            $raw = trim((string) ($response[$field] ?? ''));

            if ($raw === '') {
                continue;
            }

            // The aerodromes come back as whatever the pilot typed and are
            // resolved against the 717 the bot knows, which is not something
            // that can be done from here.
            if (str_ends_with($field, 'Aerodrome')) {
                continue;
            }

            $value = match ($spec[0]) {
                'dropdown', 'radio' => $this->chosen($field, $raw),
                default => $this->parser->parseAnswer($field, $raw),
            };

            if ($value !== null) {
                $values[$field] = $value;
            }
        }

        return (new FlightPlanFields(...$values))->merge($this->equipment($response));
    }

    /**
     * The clock the pilot typed, as four digits, or null if it was not a time.
     *
     * @param  array<string, mixed>  $response
     */
    public function departureTime(array $response): ?string
    {
        $raw = trim((string) ($response['departureTime'] ?? ''));

        return $raw === '' ? null : $this->parser->parseAnswer('departureTime', $raw);
    }

    /**
     * The day the picker came back with, written the way the calculator reads
     * a date out of a message.
     *
     * Two shapes are accepted because the picker has had two: a calendar date,
     * and milliseconds since the epoch. Anything that lands outside the window
     * the picker itself was given is treated as no answer at all rather than
     * as a date -- a seconds-for-milliseconds mix-up resolves to 1970, and a
     * flight plan filed for 1970 is not something to find out about later.
     *
     * @param  array<string, mixed>  $response
     */
    public function departureDate(array $response): ?string
    {
        $raw = trim((string) ($response['departureDate'] ?? ''));

        $date = match (true) {
            $raw === '' => null,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 => CarbonImmutable::parse($raw, 'UTC'),
            preg_match('/^\d{10,14}$/', $raw) === 1 => CarbonImmutable::createFromTimestampMs((int) $raw, 'UTC'),
            default => null,
        };

        if ($date === null) {
            return null;
        }

        $local = CarbonImmutable::now((string) config('app.display_timezone'));

        return $date->lessThan($local->subDay()) || $date->greaterThan($local->addDays(self::MAX_DAYS_AHEAD + 1))
            ? null
            : $date->format('d/m/Y');
    }

    /**
     * What the pilot wrote in each aerodrome box, for the resolver to place.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, string>
     */
    public function aerodromes(array $response): array
    {
        $named = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $raw = trim((string) ($response[$field] ?? ''));

            if (str_ends_with($field, 'Aerodrome') && $raw !== '') {
                $named[$field] = $raw;
            }
        }

        return $named;
    }

    /**
     * The submission as it arrives: a JSON string, because that is how Meta
     * sends it.
     *
     * Anything that is not an object of values is an empty submission rather
     * than an exception. A form that came back malformed is a plan the pilot
     * is asked about again; it is not worth failing the message over.
     *
     * @return array<string, mixed>
     */
    public function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function token(array $response): string
    {
        return trim((string) ($response['flow_token'] ?? ''));
    }

    /**
     * One screen of the form.
     *
     * @return array<string, mixed>
     */
    protected function screen(string $id, ?string $next, int $index): array
    {
        $spec = self::SCREENS[$id];

        $children = [['type' => 'TextCaption', 'text' => $spec['caption']]];

        foreach ($spec['fields'] as $field) {
            $children[] = $this->component($field);
        }

        $children[] = [
            'type' => 'Footer',
            'label' => $spec['next'],
            'on-click-action' => $next === null
                ? ['name' => 'complete', 'payload' => $this->payload($spec['fields'], withBounds: false)]
                : [
                    'name' => 'navigate',
                    'next' => ['type' => 'screen', 'name' => $next],
                    'payload' => $this->payload($spec['fields'], withBounds: $index + 1 <= $this->dateScreen()),
                ],
        ];

        // What each box opens filled in with.
        //
        // It hangs on the form and not on the box, which is not where anyone
        // would look for it: a component takes no starting value of its own --
        // Meta rejects the document outright for trying -- and the form is
        // handed all of them at once, keyed by the name of the box each one
        // belongs to.
        $initial = [];

        foreach ($spec['fields'] as $field) {
            $initial[$field] = '${data.'.$field.'}';
        }

        $screen = [
            'id' => $id,
            'title' => $spec['title'],
            'data' => $this->declaration(withBounds: $index <= $this->dateScreen()),
            'layout' => [
                'type' => 'SingleColumnLayout',
                'children' => [[
                    'type' => 'Form',
                    'name' => 'form',
                    'init-values' => $initial,
                    'children' => $children,
                ]],
            ],
        ];

        return $next === null ? ['terminal' => true, ...$screen] : $screen;
    }

    /**
     * One box, drawn.
     *
     * @return array<string, mixed>
     */
    protected function component(string $field): array
    {
        [$kind, $label, $helper] = self::FIELDS[$field];

        $required = ! in_array($field, self::NOT_REQUIRED, true)
            && in_array($field, FlightPlanFields::REQUIRED, true);

        $base = array_filter([
            'name' => $field,
            'label' => $label,
            'required' => $required ?: null,
            'helper-text' => $helper === '' ? null : $helper,
        ], fn (mixed $value) => $value !== null);

        return match ($kind) {
            'dropdown' => ['type' => 'Dropdown', ...$base, 'data-source' => $this->options($field)],
            'radio' => ['type' => 'RadioButtonsGroup', ...$base, 'data-source' => $this->options($field)],
            'date' => ['type' => 'DatePicker', ...$base, 'min-date' => '${data.minDate}', 'max-date' => '${data.maxDate}'],
            default => ['type' => 'TextInput', 'input-type' => 'text', ...$base],
        };
    }

    /**
     * The rows of a dropdown, out of the same table the chat's list sheets are
     * drawn from.
     *
     * @return array<int, array<string, string>>
     */
    protected function options(string $field): array
    {
        $rows = array_map(fn (array $choice) => [
            'id' => $choice[0],
            'title' => $choice[1],
            'description' => $choice[2],
        ], ReplyButton::flightPlanOptions($field));

        if ($field === 'equipmentCom') {
            $rows[] = [
                'id' => self::OTHER_EQUIPMENT,
                'title' => 'Otro — lo escribo abajo',
                'description' => 'Cualquier otra combinación del ítem 10',
            ];
        }

        return $rows;
    }

    /**
     * What every screen declares it receives.
     *
     * All of it, on every screen: a value entered on the first screen has to
     * survive to the last one to be submitted with it, and an endpointless
     * form has nowhere to keep it but its own payloads. Every screen takes the
     * whole plan in and hands the whole plan on, replacing only its own boxes.
     *
     * @return array<string, array<string, string>>
     */
    protected function declaration(bool $withBounds): array
    {
        $data = [];

        foreach (self::FIELDS as $field => $spec) {
            $data[$field] = ['type' => 'string', '__example__' => $spec[3]];
        }

        if ($withBounds) {
            foreach (self::BOUNDS as $bound) {
                $data[$bound] = ['type' => 'string', '__example__' => '2026-08-22'];
            }
        }

        return $data;
    }

    /**
     * What a screen hands on: its own boxes from the form it just drew, and
     * everything else straight through from what it was given.
     *
     * @param  array<int, string>  $own
     * @return array<string, string>
     */
    protected function payload(array $own, bool $withBounds): array
    {
        $payload = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $payload[$field] = in_array($field, $own, true)
                ? '${form.'.$field.'}'
                : '${data.'.$field.'}';
        }

        if ($withBounds) {
            foreach (self::BOUNDS as $bound) {
                $payload[$bound] = '${data.'.$bound.'}';
            }
        }

        return $payload;
    }

    /**
     * Which screen holds the date picker, and therefore how far the bounds
     * have to be carried.
     */
    protected function dateScreen(): int
    {
        foreach (array_values(self::SCREENS) as $i => $spec) {
            if (in_array('departureDate', $spec['fields'], true)) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * A chosen row, checked against the rows that box was offered.
     *
     * The id is one this class wrote and comes back verbatim, so it arrives
     * well-formed. It is still a letter on its way onto an aeronautical
     * document, and the list it has to belong to is right here.
     */
    protected function chosen(string $field, string $value): ?string
    {
        return in_array($value, array_column(ReplyButton::flightPlanOptions($field), 0), true)
            ? $value
            : null;
    }

    /**
     * Item 10, which is one box on the form and two here.
     *
     * The written-out answer wins over the dropdowns whenever there is one:
     * it is the only way to say anything the four rows do not cover, and a
     * pilot who typed it meant it. It is read the way the form prints it --
     * COM/NAV, then a slash, then the surveillance -- because that is how
     * anyone who has filled one in writes it.
     *
     * @param  array<string, mixed>  $response
     */
    protected function equipment(array $response): FlightPlanFields
    {
        $written = strtoupper(trim((string) ($response['equipmentOther'] ?? '')));

        if (preg_match('#^([A-Z][A-Z0-9]{0,19})(?:/([A-Z][A-Z0-9]{0,9}))?$#', $written, $m) !== 1) {
            return new FlightPlanFields;
        }

        return new FlightPlanFields(
            equipmentCom: $m[1],
            equipmentSurveillance: ($m[2] ?? '') === '' ? null : $m[2],
        );
    }

    /**
     * Item 10 the other way round: what the dropdowns should open on.
     *
     * An aircraft whose equipment is not one of the four rows cannot be shown
     * in the dropdown at all, so it opens on "Otro" with the real answer
     * written out underneath -- which is exactly what the pilot would have to
     * do by hand otherwise.
     *
     * @return array<string, string>
     */
    protected function equipmentPrefill(FlightPlanFields $fields): array
    {
        $com = (string) ($fields->equipmentCom ?? '');
        $surveillance = (string) ($fields->equipmentSurveillance ?? '');

        $offered = fn (string $field, string $value) => $value !== ''
            && in_array($value, array_column(ReplyButton::flightPlanOptions($field), 0), true);

        if ($com !== '' && ! $offered('equipmentCom', $com)) {
            return [
                'equipmentCom' => self::OTHER_EQUIPMENT,
                'equipmentSurveillance' => $offered('equipmentSurveillance', $surveillance) ? $surveillance : '',
                'equipmentOther' => trim($com.'/'.$surveillance, '/'),
            ];
        }

        return [
            'equipmentCom' => $offered('equipmentCom', $com) ? $com : '',
            'equipmentSurveillance' => $offered('equipmentSurveillance', $surveillance) ? $surveillance : '',
            'equipmentOther' => '',
        ];
    }

    /**
     * The departure time as the pilot thinks of it. What is stored is UTC,
     * because that is what item 13 takes, and putting that back in a box
     * labelled "hora local" is how a plan moves three hours on every pass.
     */
    protected function localClock(FlightPlanFields $fields): string
    {
        return $fields->departureAt === null
            ? ''
            : $fields->departureAt->setTimezone((string) config('app.display_timezone'))->format('H:i');
    }
}

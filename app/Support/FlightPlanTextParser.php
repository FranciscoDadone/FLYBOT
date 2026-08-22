<?php

namespace App\Support;

use App\DataObjects\FlightPlanFields;
use Illuminate\Support\Str;

/**
 * What can be read out of a pilot's message without asking a model.
 *
 * Two jobs, and the second is the one that matters. It reads a whole flight
 * plan out of a well-formed message -- which is what makes the feature work at
 * all when there is no API key configured -- and it reads a one-word answer to
 * a question the bot just asked, which is what makes "3" mean three people on
 * board rather than nothing.
 *
 * Deliberately conservative. Anything it is not sure of it leaves null, and
 * null is a question the bot asks. A wrong value that looks right is far worse
 * here than a missing one: nobody re-reads a field that is already filled in.
 *
 * Aerodromes are not resolved here. This returns the words the pilot used and
 * AirportResolver turns them into indicators, because it already knows all 717
 * of them, their names and their nicknames, and duplicating any of that would
 * be a second answer to the same question.
 */
class FlightPlanTextParser
{
    /**
     * Argentine civil registrations are LV- plus three letters; LQ- is the
     * state's. Written with or without the hyphen, and occasionally with a
     * space, which is why the match is loose and the value is normalised.
     */
    protected const REGISTRATION = '/\b(L[VQ])[\s\-]?([A-Z]{3})\b/i';

    /**
     * Everything the message states, as a set of fields to merge over a draft.
     */
    public function parse(string $message): FlightPlanFields
    {
        return new FlightPlanFields(
            aircraftIdentification: $this->registration($message),
            flightRules: $this->flightRules($message),
            level: $this->level($message),
            cruisingSpeed: $this->cruisingSpeed($message),
            totalEet: $this->labelledTime($message, ['eet']),
            route: $this->route($message),
            alternateAerodrome: $this->clearsAlternate($message) ? FlightPlanFields::CLEAR : null,
            endurance: $this->labelledTime($message, ['autonomia', 'autonomía', 'endurance']),
            personsOnBoard: $this->personsOnBoard($message),
        );
    }

    /**
     * One value, for the box the bot just asked about.
     *
     * A bare answer has no keywords to go on -- "3", "SAZR", "0220" -- so the
     * question is what gives it meaning. Returns null when the answer does not
     * fit the box, which asks again rather than storing nonsense.
     */
    public function parseAnswer(string $field, string $message): ?string
    {
        $message = trim($message);

        return match ($field) {
            'aircraftIdentification' => $this->registration($message),
            'flightRules' => $this->flightRules($message) ?? $this->letter($message, ['I', 'V', 'Y', 'Z']),
            'typeOfFlight' => $this->typeOfFlight($message) ?? $this->letter($message, ['S', 'N', 'G', 'M', 'X']),
            'wakeTurbulenceCat' => $this->letter($message, ['L', 'M', 'H', 'J']),
            'typeOfAircraft' => $this->aircraftType($message),
            'level' => $this->level($message) ?? $this->bareLevel($message),
            'cruisingSpeed' => $this->cruisingSpeed($message) ?? $this->bareSpeed($message),
            'totalEet', 'endurance' => $this->bareTime($message),
            'departureTime' => $this->bareClock($message),
            'personsOnBoard' => $this->personsOnBoard($message) ?? $this->bareCount($message),
            'equipmentCom', 'equipmentSurveillance', 'aircraftColourAndMarkings', 'pilotInCommand', 'route', 'remarks' => $message === '' ? null : mb_strtoupper($message),
            default => null,
        };
    }

    /**
     * The boxes whose answer has a shape strict enough to be recognised inside
     * a compound one.
     *
     * The rest -- the equipment, the route, the colour, the pilot's name --
     * take whatever they are given, because there is nothing to check them
     * against. Between two commas that makes them a trap: the first of them
     * still empty would swallow every piece nothing else claimed, and store
     * "no sé" as an aircraft's colour.
     *
     * @var array<int, string>
     */
    protected const GROUP_READABLE = [
        'aircraftIdentification', 'typeOfAircraft', 'wakeTurbulenceCat',
        'flightRules', 'typeOfFlight', 'cruisingSpeed', 'level',
        'totalEet', 'endurance', 'personsOnBoard',
    ];

    /**
     * Several boxes out of one answer, for a question that asked for several.
     *
     * Read in two passes. First the whole message, with the same patterns that
     * read a plan out of a sentence: they are anchored to what they mean --
     * "kt" is a speed, "FL" is a level -- so no order of the answer can fool
     * them. Then the pieces between the commas, tried against the boxes still
     * empty in the order the question named them, which is what gets "C208, L"
     * apart when neither piece says which is which.
     *
     * $group is what the question asked for and the only thing the second pass
     * may fill. $missing is everything the plan is short of, and bounds the
     * first: a compound answer fills empty boxes and never changes one that is
     * already right.
     *
     * @param  array<int, string>  $group
     * @param  array<int, string>  $missing
     */
    public function parseGroup(array $group, array $missing, string $message): FlightPlanFields
    {
        $stated = $this->parse($message);

        $values = [];

        foreach ($missing as $field) {
            if ($stated->{$field} !== null) {
                $values[$field] = $stated->{$field};
            }
        }

        $pending = array_values(array_diff($group, array_keys($values)));

        foreach ($this->chunks($message) as $chunk) {
            $pair = $this->equipmentPair($chunk);

            // Item 10 as the form prints it, and as anybody who has filled one
            // in writes it. Both halves at once, and only when both are still
            // empty -- half of a pair is not what was written.
            if ($pair !== null
                && in_array('equipmentCom', $missing, true)
                && in_array('equipmentSurveillance', $missing, true)
                && ! isset($values['equipmentCom'])) {
                $values['equipmentCom'] = $pair[0];
                $values['equipmentSurveillance'] = $pair[1];

                continue;
            }

            foreach ($pending as $i => $field) {
                if (! in_array($field, self::GROUP_READABLE, true)) {
                    continue;
                }

                $value = $this->parseAnswer($field, $chunk);

                if ($value !== null) {
                    $values[$field] = $value;
                    unset($pending[$i]);

                    break;
                }
            }
        }

        return new FlightPlanFields(...$values);
    }

    /**
     * The message cut where one answer ends and the next begins.
     *
     * @return array<int, string>
     */
    protected function chunks(string $message): array
    {
        $parts = preg_split('/\s*[,;]\s*|\s+y\s+/u', trim($message)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $p) => $p !== ''));
    }

    /**
     * "SDG/C" -- item 10 with the communication equipment on one side of the
     * slash and the surveillance on the other.
     *
     * Both halves have to start with a letter, which is what keeps "1/2" out:
     * that is how crew and passengers are written, and it is a slash between
     * two numbers.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function equipmentPair(string $chunk): ?array
    {
        return preg_match('/^([A-Z][A-Z0-9]{0,9})\/([A-Z][A-Z0-9]{0,4})$/i', trim($chunk), $m) === 1
            ? [strtoupper($m[1]), strtoupper($m[2])]
            : null;
    }

    /**
     * "LV-HAW", however it was written.
     */
    public function registration(string $message): ?string
    {
        return preg_match(self::REGISTRATION, $message, $m) === 1
            ? strtoupper($m[1]).'-'.strtoupper($m[2])
            : null;
    }

    /**
     * The route, when the message states one plainly.
     *
     * A route is an arbitrary run of airway and fix names, so there is no
     * shape to match against -- what there is, is an anchor. Either the pilot
     * named the box ("ruta ..."), or they wrote DCT, which begins one by
     * definition. From there the tokens are taken while they still look like a
     * route: upper case, two to seven characters, no punctuation. That stops
     * at the comma in "DCT, 3 a bordo" without needing to know what follows it.
     */
    public function route(string $message): ?string
    {
        if (preg_match('/\bdirecto\b/i', $message) === 1 && preg_match('/\bruta\b/i', $message) !== 1) {
            return 'DCT';
        }

        if (preg_match('/\bruta[:\s]+/i', $message, $m, PREG_OFFSET_CAPTURE) === 1) {
            $rest = substr($message, $m[0][1] + strlen($m[0][0]));
        } elseif (preg_match('/\bDCT\b/i', $message, $m, PREG_OFFSET_CAPTURE) === 1) {
            $rest = substr($message, $m[0][1]);
        } else {
            return null;
        }

        $tokens = [];

        foreach (preg_split('/\s+/', trim($rest)) ?: [] as $token) {
            $clean = rtrim($token, ',.;:');

            if (preg_match('/^[A-Z0-9]{2,7}$/', $clean) !== 1) {
                break;
            }

            $tokens[] = $clean;

            // The punctuation is where the route ended and the rest of the
            // sentence began: "DCT ASADA W68 IRASU, 2 a bordo".
            if ($clean !== $token) {
                break;
            }
        }

        return $tokens === [] ? null : implode(' ', $tokens);
    }

    /**
     * Whether the message is naming an alternate rather than a destination.
     *
     * "Santa Rosa de alternativa" names one aerodrome and means a different
     * box than "a Santa Rosa" does, and nothing about the name itself says
     * which.
     */
    public function namesAlternate(string $message): bool
    {
        return preg_match('/\b(alternativa|alternativo|alterno|altn)\b/i', Str::ascii($message)) === 1;
    }

    /**
     * Whether the message says there is no alternate, which is the only way a
     * filled box gets emptied.
     */
    public function clearsAlternate(string $message): bool
    {
        $normalised = $this->normalise($message);

        foreach (['sin alternativa', 'sin alterno', 'no llevo alternativa', 'ninguna alternativa'] as $phrase) {
            if (str_contains($normalised, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the pilot said the time in UTC. Only an explicit mark counts:
     * assuming UTC because a message looks technical is how a plan ends up
     * three hours out.
     */
    public function statesUtc(string $message): bool
    {
        return preg_match('/\b(?:z|utc|zulu|zulú)\b/i', $message) === 1
            || preg_match('/\d{3,4}\s*z\b/i', $message) === 1;
    }

    /**
     * The clock time of departure, as four digits.
     */
    public function departureTime(string $message): ?string
    {
        if (preg_match('/\b(\d{1,2})[:h.](\d{2})\b/', $message, $m) === 1) {
            return $this->clock((int) $m[1], (int) $m[2]);
        }

        if (preg_match('/\b(\d{2})(\d{2})\s*(?:z|utc|hs|horas)?\b/i', $message, $m) === 1) {
            return $this->clock((int) $m[1], (int) $m[2]);
        }

        return null;
    }

    /**
     * However the pilot referred to the day, left as words for the calculator
     * to resolve against the current date.
     */
    public function dateText(string $message): ?string
    {
        $normalised = $this->normalise($message);

        foreach (['pasado manana', 'manana', 'hoy', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'] as $word) {
            if (str_contains($normalised, $word)) {
                return $word;
            }
        }

        return preg_match('/\b(\d{1,2}[\/\-.]\d{1,2}(?:[\/\-.]\d{2,4})?)\b/', $message, $m) === 1 ? $m[1] : null;
    }

    protected function flightRules(string $message): ?string
    {
        $normalised = $this->normalise($message);

        return match (true) {
            str_contains($normalised, 'ifr') => 'I',
            str_contains($normalised, 'vfr') => 'V',
            default => null,
        };
    }

    protected function typeOfFlight(string $message): ?string
    {
        $normalised = $this->normalise($message);

        return match (true) {
            str_contains($normalised, 'regular') => 'S',
            str_contains($normalised, 'no regular') => 'N',
            str_contains($normalised, 'general') || str_contains($normalised, 'privado') => 'G',
            str_contains($normalised, 'militar') => 'M',
            default => null,
        };
    }

    protected function level(string $message): ?string
    {
        // One alternative per form rather than one pattern with three groups:
        // which branch matched is the whole answer, and reading that off a
        // group that may or may not be there is how it gets misread.
        if (preg_match('/\bFL\s?(\d{2,3})\b/i', $message, $m) === 1) {
            return 'F'.str_pad($m[1], 3, '0', STR_PAD_LEFT);
        }

        if (preg_match('/\bF(\d{3})\b/', $message, $m) === 1) {
            return 'F'.$m[1];
        }

        return preg_match('/\bA(\d{3})\b/', $message, $m) === 1 ? 'A'.$m[1] : null;
    }

    /**
     * An answer to "what level?" that is nothing but a number: hundreds of
     * feet if it is small, a flight level if it is not. 4500 is feet and 100
     * is a level, and the split is where a pilot would put it.
     */
    protected function bareLevel(string $message): ?string
    {
        $normalised = $this->normalise($message);

        if (str_contains($normalised, 'vfr')) {
            return 'VFR';
        }

        if (preg_match('/^(\d{2,5})\s*(?:pies|ft|pie)?$/', trim($normalised), $m) !== 1) {
            return null;
        }

        $value = (int) $m[1];

        return $value >= 1000
            ? 'A'.str_pad((string) (int) round($value / 100), 3, '0', STR_PAD_LEFT)
            : 'F'.str_pad((string) $value, 3, '0', STR_PAD_LEFT);
    }

    protected function cruisingSpeed(string $message): ?string
    {
        if (preg_match('/\bN(\d{4})\b/', $message, $m) === 1) {
            return 'N'.$m[1];
        }

        return preg_match('/\b(\d{2,3})\s*(?:kt|kts|nudos|nudo)\b/i', $message, $m) === 1
            ? 'N'.str_pad($m[1], 4, '0', STR_PAD_LEFT)
            : null;
    }

    protected function bareSpeed(string $message): ?string
    {
        return preg_match('/^(\d{2,4})$/', trim($message), $m) === 1
            ? 'N'.str_pad($m[1], 4, '0', STR_PAD_LEFT)
            : null;
    }

    protected function aircraftType(string $message): ?string
    {
        return preg_match('/^([A-Z0-9]{2,4})$/i', trim($message), $m) === 1
            ? strtoupper($m[1])
            : null;
    }

    /**
     * A duration written next to its name: "EET 0220", "autonomía 5 horas".
     *
     * @param  array<int, string>  $labels
     */
    protected function labelledTime(string $message, array $labels): ?string
    {
        $normalised = $this->normalise($message);

        foreach ($labels as $label) {
            $label = $this->normalise($label);

            if (preg_match('/\b'.preg_quote($label, '/').'\b[:\s]*(\d{1,2})[:h.]?(\d{2})?\b/', $normalised, $m) === 1) {
                return $this->duration((int) $m[1], (int) ($m[2] ?? 0));
            }
        }

        return null;
    }

    /**
     * An answer to "how long?" on its own: "0220", "2:20", "2 horas".
     */
    protected function bareTime(string $message): ?string
    {
        $message = trim($this->normalise($message));

        if (preg_match('/^(\d{1,2})[:h.](\d{2})$/', $message, $m) === 1) {
            return $this->duration((int) $m[1], (int) $m[2]);
        }

        if (preg_match('/^(\d{2})(\d{2})$/', $message, $m) === 1) {
            return $this->duration((int) $m[1], (int) $m[2]);
        }

        if (preg_match('/^(\d{1,2})\s*(?:h|hs|hora|horas)$/', $message, $m) === 1) {
            return $this->duration((int) $m[1], 0);
        }

        return null;
    }

    protected function bareClock(string $message): ?string
    {
        return $this->departureTime($message);
    }

    protected function personsOnBoard(string $message): ?string
    {
        $normalised = $this->normalise($message);

        // "1/2" is how the form takes crew and passengers, and it is written
        // that way often enough to read literally.
        if (preg_match('/\b(\d{1,2}\/\d{1,3})\b/', $normalised, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/\bpob\b[:\s]*(\d{1,3})\b/', $normalised, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/\b(\d{1,3})\s*(?:pob|personas?|a bordo|pax|ocupantes?)\b/', $normalised, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    protected function bareCount(string $message): ?string
    {
        return preg_match('/^(\d{1,3}(?:\/\d{1,3})?)$/', trim($message), $m) === 1 ? $m[1] : null;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    protected function letter(string $message, array $allowed): ?string
    {
        $letter = strtoupper(trim($message));

        return in_array($letter, $allowed, true) ? $letter : null;
    }

    protected function clock(int $hours, int $minutes): ?string
    {
        return $hours > 23 || $minutes > 59 ? null : sprintf('%02d%02d', $hours, $minutes);
    }

    /**
     * A duration, which unlike a clock time is allowed to pass 24 hours -- an
     * endurance of 30 hours is a ferry flight, not a typo.
     */
    protected function duration(int $hours, int $minutes): ?string
    {
        return $minutes > 59 || $hours > 99 ? null : sprintf('%02d%02d', $hours, $minutes);
    }

    protected function normalise(string $message): string
    {
        return mb_strtolower(Str::ascii($message));
    }
}

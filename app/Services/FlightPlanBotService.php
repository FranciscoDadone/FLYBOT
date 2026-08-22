<?php

namespace App\Services;

use App\DataObjects\AroAisOffice;
use App\DataObjects\FlightPlanExtraction;
use App\DataObjects\FlightPlanFields;
use App\DataObjects\ReplyButton;
use App\DataObjects\ReplyDocument;
use App\DataObjects\WhatsappReply;
use App\Models\AircraftProfile;
use App\Models\FlightPlan;
use App\Models\FlightPlanDraft;
use App\Models\PilotProfile;
use App\Support\AirportResolver;
use App\Support\AroAisDirectory;
use App\Support\FlightPlanCard;
use App\Support\FlightPlanTextParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything the bot says about a flight plan.
 *
 * Kept out of WhatsappBotService entirely: that class routes a message to a
 * topic and is already three thousand lines of doing so, and this is the first
 * topic that is a conversation rather than an answer. What it gets from there
 * is a message and a phone number; what it gives back is a WhatsappReply like
 * any other.
 *
 * The shape of the exchange is one question at a time until the form is full,
 * then a card, then a tap. Nothing is printed that the pilot has not seen on
 * the card first -- a flight plan is a document somebody presents to an AIS
 * office, and the card is the only place a wrong value can still be caught.
 */
class FlightPlanBotService
{
    public function __construct(
        protected FlightPlanDraftService $drafts,
        protected FlightPlanTextParser $parser,
        protected FlightPlanExtractor $extractor,
        protected FlightPlanCalculator $calculator,
        protected FlightPlanPdfRenderer $renderer,
        protected FlightPlanCard $card,
        protected AirportResolver $airports,
        protected AroAisDirectory $aroAis,
    ) {}

    /**
     * Whether an open plan should take this message instead of the topic the
     * router read out of it.
     *
     * While the form is being filled in, everything does. A pilot writing a
     * flight plan writes aerodromes and days and levels, which is exactly what
     * every other topic is made of, so classifying those messages on their own
     * words answers questions nobody asked -- the TAF of the destination
     * instead of the plan they are halfway through. The words that get a
     * message out of the draft are the router's, and it checks them first.
     *
     * Once the plan has been issued that stops being true. The draft is only
     * kept on for a while so a late correction has somewhere to land, and the
     * conversation is over: from there it takes back only what nothing else
     * recognised, which is what $unrecognised says.
     */
    public function capturesMessage(string $phone, bool $unrecognised): bool
    {
        $draft = $this->drafts->openFor($phone);

        return match (true) {
            $draft === null => false,
            $draft->status === FlightPlanDraft::ISSUED => $unrecognised,
            default => true,
        };
    }

    /**
     * Someone asked for a flight plan.
     */
    public function start(string $message, string $phone): WhatsappReply
    {
        $draft = $this->drafts->openFor($phone);

        // A plan that has already been generated is kept for a while so a late
        // correction lands on it, but asking for a plan is not a correction:
        // it starts a new one. Carrying the old one over would inherit the
        // route and the people on board from a flight that already happened,
        // and persons on board is what search and rescue is given -- it does
        // not get to be inherited quietly.
        if ($draft !== null && $draft->status === FlightPlanDraft::ISSUED) {
            $draft = null;
        }

        // Nothing said beyond "plan de vuelo", and nothing open: offer the
        // ways in rather than beginning an interrogation unannounced.
        if ($draft === null && $this->readFrom($message)->toStorage() === [] && $this->placesIn($message) === []) {
            return $this->welcome($phone);
        }

        $draft ??= $this->drafts->start($phone);

        return $this->absorb($draft, $message);
    }

    /**
     * A message arriving while a draft is open. Read as a correction, because
     * that is what it is nine times out of ten.
     */
    public function amend(string $message, string $phone): WhatsappReply
    {
        $draft = $this->drafts->openFor($phone);

        if ($draft === null) {
            return $this->start($message, $phone);
        }

        // The button is on every message of the flow, but a pilot who wants
        // out types it as often as they tap it, and while a draft is open
        // typing is what gets read as a correction. Saying so has to work.
        if (preg_match(self::DROP_PATTERN, $this->normalise($message)) === 1) {
            return $this->drop($phone);
        }

        return $this->absorb($draft, $message);
    }

    /**
     * A tapped button. $planId is only present on the two that act on a plan
     * already filed.
     */
    public function act(string $action, string $phone, ?int $planId): WhatsappReply
    {
        return match ($action) {
            'ok' => $this->issue($phone),
            'fix' => $this->askForCorrection($phone),
            'drop' => $this->drop($phone),
            'new' => $this->absorb($this->drafts->start($phone), ''),
            'again' => $this->repeat($phone, $planId, reversed: false),
            'back' => $this->repeat($phone, $planId, reversed: true),
            default => $this->welcome($phone),
        };
    }

    /**
     * One of a box's options, tapped.
     */
    public function choose(string $field, string $value, string $phone): WhatsappReply
    {
        $draft = $this->drafts->openFor($phone);

        if ($draft === null) {
            return $this->welcome($phone);
        }

        // The id is one this service wrote, so it arrives well-formed. It is
        // still a value on its way onto an aeronautical document, and the list
        // it has to come from is right there.
        if (! ReplyButton::isFlightPlanChoice($field, $value)) {
            return $this->present($draft);
        }

        return $this->present($this->drafts->apply($draft, new FlightPlanFields(...[$field => $value])));
    }

    /**
     * The three boxes that hold an aerodrome, and the only ones a message is
     * searched for place names on behalf of.
     *
     * @var array<int, string>
     */
    protected const AERODROME_FIELDS = ['departureAerodrome', 'destinationAerodrome', 'alternateAerodrome'];

    /**
     * Saying, rather than tapping, that the plan is off.
     *
     * Anchored at the start and to a verb, so it is the message and not
     * something inside one: "cancelar el plan" ends the draft, "salgo antes de
     * que cancelen por el viento" is a correction like any other.
     */
    protected const DROP_PATTERN = '/^(?:cancela(?:r|lo|me)?|descarta(?:r|lo|me)?|borra(?:r|lo)?|salir|olvidalo|dejalo)\b[\s\w]*$/';

    /**
     * Take everything the message says, then either ask for the next missing
     * box or show the card.
     */
    protected function absorb(FlightPlanDraft $draft, string $message): WhatsappReply
    {
        $asked = $draft->asked ?? [];
        $field = $asked[0] ?? null;

        // Which question was asked decides how the answer is read: one box
        // reads narrowly, a group reads as the compound answer it invited.
        $group = $this->card->groupFor($asked);

        $answering = $field !== null && $this->isBareAnswer($message, $group !== []);

        $incoming = match (true) {
            $answering && $group !== [] => $this->parser->parseGroup($group, $asked, $message),
            $answering => $this->readAnswer($field, $message, $draft),
            default => $this->readMessage($message, $draft, $asked),
        };

        // An answer that does not fit the box it was asked for gets a second
        // reading as an ordinary message, and if that finds nothing either the
        // pilot is told so. Silently re-asking would be a dead end: every
        // message after it is short, so every message after it would be read
        // as another answer to the same question and thrown away too.
        if ($answering && $incoming->toStorage() === []) {
            $incoming = $this->readMessage($message, $draft, $asked);
        }

        $understood = $incoming->toStorage() !== [] || trim($message) === '';

        $draft = $this->drafts->apply($draft, $incoming);

        return $this->present($draft, $understood ? null : $message);
    }

    /**
     * A short reply to a question the bot just asked, read as an answer to
     * that box and nothing else.
     *
     * Nothing else is the point. Run through the general reading, "blanco con
     * azul" answers a question about colours and quietly moves the flight to
     * Azul, which has an aerodrome -- and the pilot has no reason to re-read a
     * destination they already gave. A pending question is a narrow context
     * and is treated as one.
     */
    protected function readAnswer(string $field, string $message, FlightPlanDraft $draft): FlightPlanFields
    {
        if ($field === 'departureTime') {
            return $this->timeFrom($message, $draft);
        }

        if (in_array($field, self::AERODROME_FIELDS, true)) {
            $places = $this->placesIn($message);

            return $places === [] ? new FlightPlanFields : $this->place($field, $places[0]);
        }

        $answer = $this->parser->parseAnswer($field, $message);

        return $answer === null ? new FlightPlanFields : new FlightPlanFields(...[$field => $answer]);
    }

    /**
     * A message that is saying something rather than answering something:
     * everything in it counts.
     *
     * Both readings run, and the deterministic one wins wherever the two
     * overlap. A regex that matched "N0175" matched it; the model is what
     * catches the rest of the sentence -- "un Caravan blanco con azul, salgo
     * temprano el viernes" -- which no pattern was ever going to.
     *
     * @param  array<int, string>  $asked
     */
    protected function readMessage(string $message, FlightPlanDraft $draft, array $asked): FlightPlanFields
    {
        $extraction = $this->extractor->extract($message, $draft, $asked);

        $fields = $extraction === null ? new FlightPlanFields : $extraction->fields;

        $fields = $fields
            ->merge($this->parser->parse($message))
            ->merge($this->placesFrom($message, $draft, $asked, $extraction))
            ->merge($this->timeFrom($message, $draft, $extraction));

        return $fields;
    }

    /**
     * The card, or the next question.
     */
    protected function present(FlightPlanDraft $draft, ?string $notUnderstood = null): WhatsappReply
    {
        $missing = $draft->fields()->missing();

        if ($missing === []) {
            $this->drafts->remember($draft, []);

            return new WhatsappReply(
                [$this->card->render($draft), '¿Lo genero así?'],
                ReplyButton::flightPlanDraft(true),
            );
        }

        $this->drafts->remember($draft, $missing);

        $question = $this->card->question($missing);

        if ($notUnderstood !== null) {
            $question = 'No entendí _"'.trim($notUnderstood).'"_ para eso.'."\n\n".$question;
        }

        // The card only leads once there is enough on it to be worth reading:
        // before that it is a row of question marks, and the question alone is
        // the clearer message. A correction that landed nowhere leaves the
        // draft as it was, so there is no new card to show either.
        $bodies = $notUnderstood === null && count($missing) <= count(FlightPlanFields::REQUIRED) - 4
            ? [$this->card->render($draft), (string) $question]
            : [(string) $question];

        // A box whose answer is one of four letters is offered as four rows to
        // tap. Only when it is being asked on its own: a sheet under a question
        // about three boxes would answer one of them and look like it had
        // answered all three.
        $choices = $this->card->groupFor($missing) === []
            ? ReplyButton::flightPlanChoices($missing[0])
            : null;

        return new WhatsappReply($bodies, $choices ?? ReplyButton::flightPlanCancel());
    }

    /**
     * Render the plan, file it, and hand it over.
     */
    protected function issue(string $phone): WhatsappReply
    {
        $draft = $this->drafts->openFor($phone);

        if ($draft === null) {
            return $this->welcome($phone);
        }

        $fields = $draft->fields();

        try {
            $pdf = $this->renderer->render($fields);
        } catch (Throwable $e) {
            report($e);

            return WhatsappReply::of(
                'No pude armar el PDF del plan. El borrador quedó guardado: probá de nuevo en un rato.',
            );
        }

        $plan = $this->store($phone, $fields, $pdf);

        $this->drafts->markIssued($draft);
        $this->rememberProfiles($phone, $fields);

        $messages = [$this->presentationMessage($fields)];

        foreach ($this->renderer->overflowed() as $field) {
            $messages[] = "⚠️ El campo *{$field}* es más largo de lo que entra en el formulario. "
                .'Revisalo en el PDF antes de presentarlo.';
        }

        return WhatsappReply::ofDocuments(
            [new ReplyDocument(
                url: route('fpl.pdf', ['token' => $plan->pdf_token]),
                caption: $this->caption($fields),
                filename: $plan->filename(),
            )],
            $messages,
            ReplyButton::flightPlanIssued($plan->id),
        );
    }

    protected function store(string $phone, FlightPlanFields $fields, string $pdf): FlightPlan
    {
        $token = FlightPlan::newToken();
        $path = config('fpl.directory').'/'.$token.'.pdf';

        Storage::disk((string) config('fpl.disk'))->put($path, $pdf);

        return FlightPlan::create([
            'phone' => $phone,
            'data' => $fields->toStorage(),
            'departure_anac' => $fields->departureAnacCode,
            'departure_icao' => $fields->departureAerodrome,
            'destination_anac' => $fields->destinationAnacCode,
            'destination_icao' => $fields->destinationAerodrome,
            'departure_at' => $fields->departureAt,
            'pdf_disk' => (string) config('fpl.disk'),
            'pdf_path' => $path,
            'pdf_token' => $token,
            'pdf_expires_at' => CarbonImmutable::now()->addHours((int) config('fpl.pdf_ttl_hours')),
        ]);
    }

    /**
     * Where to present it, and the reminder that presenting it is the pilot's
     * job and not the bot's.
     *
     * That last line is not a disclaimer. The bot fills in a form; filing it is
     * an act with consequences, performed by the person who signs it, and a
     * message that left that ambiguous would be inviting somebody to assume the
     * plan is already in.
     */
    protected function presentationMessage(FlightPlanFields $fields): string
    {
        $lines = ['📨 *Dónde presentarlo*', ''];

        foreach ($this->offices($fields) as $office) {
            $lines[] = $office->isControlCentre
                ? "*{$office->name}* — el ACC de tu FIR, se presenta por teléfono"
                : "*ARO/AIS {$office->name}* ({$office->icaoCode})";

            if ($office->email !== null) {
                $lines[] = "✉️ {$office->email}";
            }

            if ($office->telephone !== null) {
                $lines[] = "☎️ {$office->telephone}";
            }

            $lines[] = '';
        }

        if (count($lines) === 2) {
            $lines[] = 'No tengo la oficina que te corresponde. Consultala en el AIP o por frecuencia.';
            $lines[] = '';
        }

        $lines[] = 'Presentalo con *30 minutos de anticipación* como mínimo.';
        $lines[] = '';
        $lines[] = '_Revisá el PDF antes de mandarlo: el plan lo presentás vos, yo sólo te lo armo._';

        return implode("\n", $lines);
    }

    /**
     * @return array<int, AroAisOffice>
     */
    protected function offices(FlightPlanFields $fields): array
    {
        $airport = $this->drafts->airport($fields->departureAnacCode);

        return $airport === null ? [] : $this->aroAis->forAirport($airport);
    }

    protected function caption(FlightPlanFields $fields): string
    {
        // The indicator the form carries, except where that is ZZZZ and says
        // nothing: there the ANAC code is the only thing that names the place.
        $indicator = fn (?string $icao, ?string $anac) => $icao === 'ZZZZ' ? $anac : $icao;

        $route = implode(' → ', array_filter([
            $indicator($fields->departureAerodrome, $fields->departureAnacCode),
            $indicator($fields->destinationAerodrome, $fields->destinationAnacCode),
        ]));

        return trim(implode(' · ', array_filter([
            'Plan de vuelo '.($fields->aircraftIdentification ?? ''),
            $route === '' ? null : $route,
            $fields->departureAt?->format('d/m H:i').'Z',
        ])));
    }

    protected function askForCorrection(string $phone): WhatsappReply
    {
        $draft = $this->drafts->openFor($phone);

        if ($draft === null) {
            return $this->welcome($phone);
        }

        // Nothing in particular is being waited for now: whatever comes next is
        // read against the whole plan.
        $this->drafts->remember($draft, []);

        return new WhatsappReply([
            "Decime qué cambio y lo corrijo.\n\n"
            ."_Por ejemplo:_\n"
            ."_• 3 a bordo_\n"
            ."_• ruta DCT ASADA W68 IRASU_\n"
            ."_• salgo 20:15_\n"
            .'_• sin alternativa_',
        ], ReplyButton::flightPlanCancel());
    }

    /**
     * Close the plan and give the pilot the rest of the bot back.
     *
     * What it says depends on whether the PDF was already sent, because
     * discard() only removes the draft: the plan and its document survive it,
     * and someone who taps this after receiving one has to be told that.
     */
    protected function drop(string $phone): WhatsappReply
    {
        $issued = $this->drafts->openFor($phone)?->status === FlightPlanDraft::ISSUED;

        $this->drafts->discard($phone);

        return WhatsappReply::of($issued
            ? 'Listo, cerré el plan — el PDF que te mandé sigue estando. Preguntame lo que quieras.'
            : 'Listo, descarté el borrador. Escribime *plan de vuelo* cuando quieras arrancar otro.');
    }

    /**
     * Start again from a plan already filed, either as it was or the other way
     * round.
     */
    protected function repeat(string $phone, ?int $planId, bool $reversed): WhatsappReply
    {
        $plan = $planId === null
            ? null
            : FlightPlan::query()->forPhone($phone)->find($planId);

        if ($plan === null) {
            return $this->welcome($phone);
        }

        $fields = $plan->fields();

        if ($reversed) {
            $fields = $this->reverse($fields);
        }

        // The date and the times are the two things that never carry over.
        // Everything else about the same aircraft on the same leg does.
        $fields = $fields->merge(new FlightPlanFields(
            departureTime: FlightPlanFields::CLEAR,
            otherInformation: FlightPlanFields::CLEAR,
        ));

        $draft = $this->drafts->start($phone, $fields, $plan);

        return $this->absorb($draft, '');
    }

    /**
     * The same flight the other way.
     *
     * The route is not reversed with it. Turning "DCT ASADA W68 IRASU W73 TIMKU
     * OSA" around token by token produces something that reads like a valid
     * route and is not one -- airways are directional and the fixes on them are
     * not symmetric. DCT is the one case that does reverse, because direct is
     * direct either way; anything else is cleared and asked for.
     */
    protected function reverse(FlightPlanFields $fields): FlightPlanFields
    {
        $route = trim((string) $fields->route);

        return $fields->merge(new FlightPlanFields(
            departureAerodrome: $fields->destinationAerodrome,
            departureAnacCode: $fields->destinationAnacCode,
            destinationAerodrome: $fields->departureAerodrome,
            destinationAnacCode: $fields->departureAnacCode,
            route: strtoupper($route) === 'DCT' ? 'DCT' : FlightPlanFields::CLEAR,
        ));
    }

    protected function welcome(string $phone): WhatsappReply
    {
        $last = FlightPlan::query()->forPhone($phone)->mostRecentFirst()->first();

        $body = "✈️ *Plan de vuelo*\n\n"
            ."Contame el vuelo en un mensaje y te armo el PDF del formulario de EANA.\n\n"
            ."_Por ejemplo:_\n"
            ."_LV-HAW de San Fernando a Santa Rosa mañana 19:45, FL100, 2 a bordo_\n\n"
            .'Lo que falte te lo pregunto.';

        return new WhatsappReply([$body], ReplyButton::flightPlanStart($last?->id));
    }

    /**
     * Lowercased, unaccented and stripped of the punctuation people end a
     * message with, so DROP_PATTERN can be written once.
     */
    protected function normalise(string $message): string
    {
        return trim(Str::ascii(mb_strtolower(trim($message))), " \t\n.,;:!¡?¿");
    }

    /**
     * What the message says, deterministically. Used to tell a message that
     * carries a flight plan from one that is only asking for the topic.
     */
    protected function readFrom(string $message): FlightPlanFields
    {
        return $this->parser->parse($message);
    }

    /**
     * Whether the message is short enough to be an answer rather than a
     * sentence. A long message says what it means; a two-word one only means
     * something against the question it follows.
     *
     * A question that asked for three things gets more room, because "175 kt,
     * FL100, DCT" is still an answer and not a sentence -- it is three of them.
     */
    protected function isBareAnswer(string $message, bool $grouped = false): bool
    {
        $message = trim($message);

        return str_word_count($message) <= ($grouped ? 8 : 4)
            && mb_strlen($message) <= ($grouped ? 80 : 40);
    }

    /**
     * The aerodromes named in the message, assigned to departure, destination
     * and alternate.
     *
     * @param  array<int, string>  $asked
     */
    protected function placesFrom(string $message, FlightPlanDraft $draft, array $asked, ?FlightPlanExtraction $extraction = null): FlightPlanFields
    {
        $places = $this->placesIn($message);

        // Nothing recognisable in the message itself, but the model may have
        // told us which words were meant to be places. They still go through
        // AirportResolver: what the model contributes is where to look, never
        // what the answer is.
        if ($places === [] && $extraction !== null) {
            return $this->placesFromExtraction($extraction);
        }

        if ($places === []) {
            return new FlightPlanFields;
        }

        // A single aerodrome answering a question about one goes where the
        // question was: otherwise "santa rosa" after "which alternate?" would
        // become the destination.
        $field = $asked[0] ?? null;

        if (count($places) === 1 && in_array($field, ['departureAerodrome', 'destinationAerodrome', 'alternateAerodrome'], true)) {
            return $this->place($field, $places[0]);
        }

        $fields = new FlightPlanFields;
        $current = $draft->fields();

        // Two or more: the first is where the flight starts and the second is
        // where it ends, which is what "de X a Y" means and how anyone would
        // write it.
        //
        // A third only becomes the alternate if the message says so. Position
        // is not enough for that box: the message is cut into pieces and each
        // piece resolved on its own, so a sentence that goes on past the route
        // -- "3 a bordo, color verde" -- leaves a piece that a name lookup is
        // glad to read as Isla Verde. Nobody gives an alternate without naming
        // it as one, and an alternate nobody asked for is the kind of wrong
        // that gets printed and signed.
        $order = $this->parser->namesAlternate($message)
            ? ['departureAerodrome', 'destinationAerodrome', 'alternateAerodrome']
            : ['departureAerodrome', 'destinationAerodrome'];

        if (count($places) === 1) {
            // Only one, and no question pending: the message says which box it
            // belongs in, or it fills the first empty leg -- overwriting a leg
            // that is already right is the failure to avoid here.
            $order = match (true) {
                $this->parser->namesAlternate($message) => ['alternateAerodrome'],
                $current->departureAerodrome === null => ['departureAerodrome'],
                default => ['destinationAerodrome'],
            };
        }

        foreach (array_slice($places, 0, count($order)) as $i => $anacCode) {
            $fields = $fields->merge($this->place($order[$i], $anacCode));
        }

        return $fields;
    }

    /**
     * The places the model picked out of the message, resolved the ordinary
     * way.
     */
    protected function placesFromExtraction(FlightPlanExtraction $extraction): FlightPlanFields
    {
        $fields = new FlightPlanFields;

        $named = [
            'departureAerodrome' => $extraction->departureText,
            'destinationAerodrome' => $extraction->destinationText,
            'alternateAerodrome' => $extraction->alternateText,
        ];

        foreach ($named as $field => $text) {
            if ($text === null) {
                continue;
            }

            $anac = $this->airports->matchFromText($text);

            if ($anac !== null) {
                $fields = $fields->merge($this->place($field, $anac));
            }
        }

        return $fields;
    }

    /**
     * One aerodrome, written both ways: the indicator the form takes and the
     * ANAC code everything else here speaks.
     */
    protected function place(string $field, string $anacCode): FlightPlanFields
    {
        $airport = $this->drafts->airport($anacCode);

        return new FlightPlanFields(...[
            $field => $this->drafts->indicatorFor($airport),
            match ($field) {
                'departureAerodrome' => 'departureAnacCode',
                'destinationAerodrome' => 'destinationAnacCode',
                default => 'alternateAnacCode',
            } => $anacCode,
        ]);
    }

    /**
     * Every aerodrome the message names, in the order it names them.
     *
     * A flight plan mentions two or three, which is why this cannot use the
     * bot's ordinary single-aerodrome matching. The message is split on the
     * words that separate an origin from a destination and each piece is
     * resolved on its own, so "de San Fernando a Santa Rosa" reads as two
     * places and not as one long name.
     *
     * @return array<int, string>
     */
    protected function placesIn(string $message): array
    {
        $found = [];

        // An explicit indicator wins over anything a name lookup would do with
        // the surrounding words.
        if (preg_match_all('/\b(S[AB][A-Z]{2})\b/i', $message, $m) >= 1) {
            foreach ($m[1] as $icao) {
                $anac = $this->airports->resolve(strtoupper($icao));

                if ($this->airports->exists($anac)) {
                    $found[] = $anac;
                }
            }
        }

        if ($found !== []) {
            return array_values(array_unique($found));
        }

        foreach ($this->segments($message) as $segment) {
            $anac = $this->airports->matchFromText($segment);

            if ($anac !== null) {
                $found[] = $anac;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The message cut where one aerodrome stops and the next begins.
     *
     * @return array<int, string>
     */
    protected function segments(string $message): array
    {
        $normalised = ' '.trim($message).' ';

        $split = preg_split(
            '/\s+(?:a|hasta|hacia|para|con destino a|alternativa|altn|y vuelta a)\s+/iu',
            $normalised,
        ) ?: [];

        $segments = array_values(array_filter(array_map('trim', $split), fn (string $s) => $s !== ''));

        // Nothing to split on: the whole message is one place, or none.
        return $segments === [] ? [trim($message)] : $segments;
    }

    /**
     * The departure time, and the instant it resolves to.
     *
     * Only ever recomputed from something the message actually said. The
     * stored departureTime is already UTC, so feeding it back through the
     * local-time conversion would move the flight three hours later on every
     * message -- silently, and in a direction nobody would think to check.
     */
    protected function timeFrom(string $message, FlightPlanDraft $draft, ?FlightPlanExtraction $extraction = null): FlightPlanFields
    {
        $current = $draft->fields();

        $time = $this->parser->departureTime($message) ?? $extraction?->departureTime;
        $dateText = $this->parser->dateText($message) ?? $extraction?->dateText;

        if ($time !== null) {
            // The pilot said UTC only if they wrote it. The model is asked the
            // same question and is believed only when the message itself did
            // not answer it.
            $isUtc = $this->parser->statesUtc($message) || ($extraction?->timeIsUtc === true);

            $at = $this->calculator->departureAt($time, $isUtc, $dateText);

            // Item 13 is UTC and what the pilot typed was almost certainly
            // local. This is the single conversion the whole form turns on,
            // which is why the card prints both clocks.
            return new FlightPlanFields(
                departureTime: $at?->format('Hi') ?? $time,
                departureAt: $at,
            );
        }

        // A message that moves the day without restating the clock: keep the
        // local time the pilot already gave and put it on the new date.
        if ($dateText !== null && $current->departureAt !== null) {
            $local = $current->departureAt->setTimezone((string) config('app.display_timezone'));

            $at = $this->calculator->departureAt($local->format('Hi'), isUtc: false, dateText: $dateText);

            return new FlightPlanFields(
                departureTime: $at?->format('Hi') ?? $current->departureTime,
                departureAt: $at,
            );
        }

        return new FlightPlanFields;
    }

    /**
     * Keep what this plan taught us about the aircraft and the pilot, so the
     * next one is shorter.
     *
     * Only ever adds: a profile column is left alone unless the plan actually
     * filled it, so a plan missing the colours does not erase the colours.
     */
    protected function rememberProfiles(string $phone, FlightPlanFields $fields): void
    {
        if ($fields->pilotInCommand !== null) {
            PilotProfile::updateOrCreate(
                ['phone' => $phone],
                array_filter([
                    'name' => $fields->pilotInCommand,
                    'filed_by' => $fields->filedBy,
                ]),
            );
        }

        if ($fields->aircraftIdentification === null) {
            return;
        }

        $profile = AircraftProfile::query()
            ->forPhone($phone)
            ->matching($fields->aircraftIdentification)
            ->first()
            ?? new AircraftProfile(['phone' => $phone, 'registration' => $fields->aircraftIdentification]);

        $profile->fill(array_filter([
            'aircraft_type' => $fields->typeOfAircraft,
            'wake_turbulence' => $fields->wakeTurbulenceCat,
            'equipment_com' => $fields->equipmentCom,
            'equipment_surveillance' => $fields->equipmentSurveillance,
            'cruising_speed' => $fields->cruisingSpeed,
            'cruise_tas_kt' => $this->knots($fields->cruisingSpeed),
            'default_level' => $fields->level,
            'endurance' => $fields->endurance,
            'colour_markings' => $fields->aircraftColourAndMarkings,
            'emergency_radio' => $fields->emergencyRadio,
            'survival_equipment' => $fields->survivalEquipment,
            'jackets' => $fields->jackets,
        ], fn (mixed $value) => $value !== null && $value !== []));

        $profile->times_used = $profile->times_used + 1;
        $profile->last_used_at = now();
        $profile->save();
    }

    protected function knots(?string $cruisingSpeed): ?int
    {
        return $cruisingSpeed !== null && preg_match('/^N(\d{4})$/', $cruisingSpeed, $m) === 1
            ? (int) $m[1]
            : null;
    }
}

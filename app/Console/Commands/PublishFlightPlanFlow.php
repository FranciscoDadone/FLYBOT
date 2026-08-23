<?php

namespace App\Console\Commands;

use App\Support\FlightPlanFlow;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Puts the flight plan form up at Meta, or replaces the one that is there.
 *
 * A Flow is not sent with the message the way buttons are: the document lives
 * on the WhatsApp Business Account, is published once, and from then on a
 * message only names its id. Which makes this the deploy step for anything
 * that changes in FlightPlanFlow -- a renamed box or a new screen does nothing
 * at all until this has run.
 *
 * Run by hand and never from a queue. It writes to the business account rather
 * than to the number, needs a token with whatsapp_business_management on it,
 * and the thing it changes is what every pilot sees next time they open the
 * form. It does not touch the webhook: an endpointless Flow has no callbacks
 * to subscribe to, and the submission arrives on the messages webhook that is
 * already there.
 */
class PublishFlightPlanFlow extends Command
{
    protected $signature = 'fpl:flow
        {--json : Print the Flow JSON and stop, without talking to Meta}
        {--draft : Upload it but leave it unpublished, to try against the test number first}';

    protected $description = 'Create or update the flight plan Flow at Meta';

    public function handle(FlightPlanFlow $flow): int
    {
        $document = (string) json_encode($flow->document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($this->option('json')) {
            $this->line($document);

            return self::SUCCESS;
        }

        $token = config('services.whatsapp.token');
        $account = config('services.whatsapp.business_account_id');

        if (blank($token) || blank($account)) {
            $this->error('Faltan WHATSAPP_ACCESS_TOKEN y WHATSAPP_BUSINESS_ACCOUNT_ID.');

            return self::FAILURE;
        }

        $id = config('fpl.flow.id');

        return blank($id)
            ? $this->create($document)
            : $this->update((string) $id, $document);
    }

    /**
     * The first time: the account has no form of ours on it yet.
     *
     * Never published outright. The id it hands back has to be written into
     * the environment before a single message can reference it, so publishing
     * in the same breath would leave a live form that nothing is pointing at.
     */
    protected function create(string $document): int
    {
        $account = (string) config('services.whatsapp.business_account_id');

        $response = $this->client()->post("{$account}/flows", [
            'name' => (string) config('fpl.flow.name'),
            'categories' => ['OTHER'],
            'flow_json' => $document,
            'publish' => false,
        ]);

        $id = $response->json('id');

        if (! $response->successful() || ! is_string($id)) {
            return $this->refuse($response->json());
        }

        $this->report($response->json('validation_errors'));

        $this->info("Flow creado: {$id}");
        $this->line('');
        $this->line('Poné esto en el .env y volvé a correr el comando para publicarlo:');
        $this->line("WHATSAPP_FPL_FLOW_ID={$id}");

        return self::SUCCESS;
    }

    /**
     * Every time after that: the same id keeps its published messages working
     * while a new version of the document goes up behind it.
     */
    protected function update(string $id, string $document): int
    {
        $response = $this->client()
            ->attach('file', $document, 'flow.json', ['Content-Type' => 'application/json'])
            ->post("{$id}/assets", [
                'name' => 'flow.json',
                'asset_type' => 'FLOW_JSON',
            ]);

        if (! $response->successful()) {
            return $this->refuse($response->json());
        }

        $errors = $this->report($response->json('validation_errors'));

        if ($errors > 0) {
            $this->error('No lo publico con errores de validación sin resolver.');

            return self::FAILURE;
        }

        $this->info("Documento subido al flow {$id}.");

        if ($this->option('draft')) {
            $this->line('Queda sin publicar. Para probarlo, WHATSAPP_FPL_FLOW_MODE=draft.');

            return self::SUCCESS;
        }

        $published = $this->client()->post("{$id}/publish");

        if (! $published->successful()) {
            // Meta calls this one "Blocked by Integrity", which says nothing.
            // It means the business behind the account is not verified: a test
            // account never is, and a real one has to go through Meta's own
            // verification before it can publish a form at all. The document
            // is uploaded either way, and can be sent with mode "draft" to the
            // app's own testers in the meantime.
            if ((int) $published->json('error.code') === 139000) {
                $this->error('Meta no deja publicar: la empresa dueña de la cuenta no está verificada.');
                $this->line('El documento quedó subido igual. Para probarlo mientras tanto:');
                $this->line('WHATSAPP_FPL_FLOW_MODE=draft  (sólo llega a los testers de la app)');

                return self::FAILURE;
            }

            return $this->refuse($published->json());
        }

        $this->info('Publicado.');

        return self::SUCCESS;
    }

    /**
     * Meta answers a bad document with the line and column of every complaint.
     * Printed as it comes: guessing at what it meant would be slower than
     * reading it.
     *
     * @param  mixed  $errors
     */
    protected function report($errors): int
    {
        if (! is_array($errors) || $errors === []) {
            return 0;
        }

        foreach ($errors as $error) {
            $this->warn(sprintf(
                '%s (línea %s): %s',
                $error['error'] ?? 'ERROR',
                $error['line_start'] ?? '?',
                $error['message'] ?? '',
            ));
        }

        return count($errors);
    }

    /**
     * @param  mixed  $body
     */
    protected function refuse($body): int
    {
        $this->error((string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }

    protected function client(): PendingRequest
    {
        $version = config('services.whatsapp.graph_version');

        return Http::withToken((string) config('services.whatsapp.token'))
            ->baseUrl("https://graph.facebook.com/{$version}/")
            ->timeout(60);
    }
}

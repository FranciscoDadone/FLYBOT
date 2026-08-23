<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the rendered plans live
    |--------------------------------------------------------------------------
    |
    | The PDF is written to disk and then fetched by WhatsApp over a public URL
    | -- Meta downloads the file itself, so nothing is uploaded from here and
    | the file has to outlive the message by enough for the pilot to open it.
    |
    */

    'disk' => env('FPL_DISK', 'local'),

    'directory' => 'flight-plans',

    /*
    | mPDF writes its font cache and image temporaries here. It must be
    | writable by the user the worker runs as, which under FrankenPHP is
    | www-data and not root.
    */
    'temp_dir' => storage_path('app/private/mpdf'),

    /*
    | The blank EANA form the fields are drawn on top of, and the pixel grid
    | the coordinates in resources/data/fpl-layout.php are expressed in.
    */
    'background' => resource_path('images/fpl/eana-fpl.jpg'),

    /*
    |--------------------------------------------------------------------------
    | El formulario dentro de WhatsApp
    |--------------------------------------------------------------------------
    |
    | Un Flow vive publicado en Meta y se referencia por id: `php artisan
    | fpl:flow` sube el documento que arma App\Support\FlightPlanFlow y
    | devuelve ese id. Sin id configurado el bot pregunta casilla por casilla
    | como antes -- que es también lo que corre en los tests y en el endpoint
    | local, donde no hay Meta a la que pedirle nada.
    |
    | La versión del Flow JSON es la del compilador de Meta, no la nuestra. Si
    | al publicar contesta que no la soporta, se baja acá y se vuelve a subir.
    |
    */

    'flow' => [
        'id' => env('WHATSAPP_FPL_FLOW_ID'),

        'name' => env('WHATSAPP_FPL_FLOW_NAME', 'Plan de vuelo'),

        // "draft" sirve para probar una versión nueva contra el propio número
        // sin publicarla; en producción es siempre "published".
        'mode' => env('WHATSAPP_FPL_FLOW_MODE', 'published'),

        'json_version' => env('WHATSAPP_FPL_FLOW_JSON_VERSION', '7.3'),

        'cta' => '📝 Completar el plan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Lifetimes
    |--------------------------------------------------------------------------
    |
    | A draft goes stale rather than insecure: after a few hours its DOF and
    | its departure time are wrong, and confirming it blind would be worse
    | than starting again. The PDF outlives it because the pilot may open the
    | attachment well after the conversation ended.
    |
    */

    'draft_ttl_hours' => (int) env('FPL_DRAFT_TTL_HOURS', 3),

    'pdf_ttl_hours' => (int) env('FPL_PDF_TTL_HOURS', 48),

    'history_days' => (int) env('FPL_HISTORY_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Estimates
    |--------------------------------------------------------------------------
    |
    | Both of these are proposals shown to the pilot, never values sent to a
    | PDF without being read. The EET is still-air: no wind is modelled, which
    | is why the reply says so every time.
    |
    */

    'climb_descent_minutes' => 10,

    'reserve_minutes' => 45,

];

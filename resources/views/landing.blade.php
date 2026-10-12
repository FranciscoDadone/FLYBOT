<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FlyBot — NOTAM, METAR y TAF de Argentina, en español y por WhatsApp</title>
<meta name="description" content="Es gratis: escribí «EZE» por WhatsApp y recibí los NOTAM, el METAR, el TAF, el PRONAREA y más de cualquier aeródromo argentino, decodificados a español legible.">
<meta name="theme-color" content="#ffffff">
<meta property="og:title" content="FlyBot — NOTAM, METAR y TAF en español">
<meta property="og:description" content="Gratis: el parte aeronáutico argentino, decodificado y por WhatsApp.">
<meta property="og:type" content="website">
<meta property="og:image" content="{{ url('/images/flybot-og.jpg') }}">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/images/flybot-touch.png">
<meta name="csrf-token" content="{{ csrf_token() }}">
@vite(['resources/css/app.css', 'resources/js/chat.js'])
</head>
<body>

@include('partials.nav')

<header id="top" class="pt-5 pb-14 sm:pt-8 sm:pb-20 lg:pt-6">
  <div class="mx-auto grid w-full max-w-[1120px] items-center gap-6 px-4 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,560px)] lg:gap-10 xl:grid-cols-[minmax(0,1fr)_minmax(0,620px)] xl:gap-12">
    <div class="text-center lg:text-left">
      <div class="flex flex-wrap items-center justify-center gap-2.5 lg:justify-start">
        <span class="inline-flex items-center rounded-lg border border-air/25 bg-air-soft px-3 py-1.5 font-mono text-xs font-medium tracking-[0.1em] text-air uppercase">
          Tu asistente aeronáutico · Argentina
        </span>
        <span class="inline-flex items-center rounded-lg border border-wa/25 bg-[#e7f7ee] px-3 py-1.5 font-mono text-xs font-medium tracking-[0.1em] text-wa uppercase">
          Gratis
        </span>
      </div>

      <h1 class="mx-auto mt-5 mb-3 max-w-[22em] text-[clamp(1.6rem,3.4vw,2.7rem)] font-semibold leading-[1.1] tracking-[-0.03em] lg:mx-0 lg:text-[2.1rem] xl:text-[2.7rem]">
        NOTAM, METAR y TAF de los aeródromos argentinos, <span class="text-air">en español</span>.
      </h1>

      <p class="mx-auto max-w-[30em] text-[clamp(1rem,1.5vw,1.15rem)] leading-relaxed text-muted lg:mx-0">
        Preguntale acá mismo, como en WhatsApp: el mismo bot, las mismas respuestas.
      </p>

      <ul class="checks mt-8 hidden text-left xl:grid">
        <li><span>Escribí como hablás: <strong>«¿va a llover mañana en Bariloche?»</strong></span></li>
        <li><span>El texto crudo siempre arriba, la lectura en español debajo.</span></li>
        <li><span>Sin registro y sin instalar nada. Y si querés que te avise cuando cambia el clima, <a href="#whatsapp" class="text-air hover:underline">seguilo por WhatsApp</a>.</span></li>
      </ul>
    </div>

    <section id="chat" data-chat data-endpoint="{{ route('chat') }}"
             class="relative flex h-[calc(100svh-5.5rem)] max-h-[780px] min-h-[440px] lg:h-[calc(100svh-7rem)] scroll-mt-20 flex-col overflow-hidden rounded-2xl border border-line shadow-[0_18px_50px_-24px_rgba(15,21,27,.35)]">
      <div class="flex items-center gap-3 bg-wa-head px-4 py-2.5 text-white">
        <img src="/images/flybot-logo-128.webp" alt="" width="40" height="40" class="size-10 rounded-full bg-white">
        <div class="min-w-0 flex-1">
          <b class="block text-[1rem] font-semibold tracking-[-0.01em]">FlyBot</b>
          <small data-chat-status class="block text-[0.78rem] text-white/80" aria-live="polite">en línea</small>
        </div>
        @if ($link)
          <a href="{{ $link }}" target="_blank" rel="noopener"
             class="rounded-full border border-white/40 px-3 py-1.5 text-[0.8rem] font-medium whitespace-nowrap hover:bg-white/10">
            Seguir en WhatsApp
          </a>
        @endif
      </div>

      <div data-chat-log class="chat-log flex-1" role="log" aria-live="polite">
        <div class="mx-auto mb-2 rounded-lg bg-[#fff5c4] px-3 py-1.5 text-center text-[0.75rem] text-[#54656f] shadow-[0_1px_0.5px_rgba(11,20,26,.13)]">
          🔒 Gratis y sin registro. Lo que escribís queda guardado para mejorar el bot — <a href="/privacidad" class="underline">privacidad</a>.
        </div>
        <div class="chat-row chat-row-in">
          <div class="chat-bubble chat-bubble-in">
            ¡Hola! Soy <strong>FlyBot</strong> ✈️<br>
            Decime un aeródromo de Argentina —<strong>EZE</strong>, <strong>SAEZ</strong> o <strong>Ezeiza</strong>— y te paso su ficha, NOTAM, METAR, TAF y más, decodificados a español.<br><br>
            También podés preguntar como hablás: <em>«¿hay notams en Aeroparque?»</em>, <em>«va a llover mañana en Bariloche?»</em>.
          </div>
        </div>
      </div>

      <div data-chat-suggestions class="flex gap-2 overflow-x-auto bg-wa-wall px-3 pb-2 sm:px-6">
        @foreach (['EZE', 'metar Aeroparque', 'taf Bariloche', 'notams Córdoba', 'viento en pista SAEZ', 'crepúsculo Santa Rosa', 'pronarea EZE'] as $suggestion)
          <button type="button" class="chat-suggestion" data-suggestion="{{ $suggestion }}">{{ $suggestion }}</button>
        @endforeach
      </div>

      <form data-chat-form class="flex items-center gap-2 bg-[#f0f2f5] px-3 py-2.5">
        <label for="chat-input" class="sr-only">Mensaje</label>
        <input id="chat-input" type="text" maxlength="500" autocomplete="off" enterkeyhint="send"
               placeholder="Escribí un aeródromo o una pregunta"
               class="min-w-0 flex-1 rounded-full border border-transparent bg-ink px-4 py-2.5 text-[16px] outline-none focus:border-wa-head/40">
        <button type="submit" aria-label="Enviar"
                class="grid size-11 shrink-0 cursor-pointer place-items-center rounded-full bg-wa-head text-white transition-opacity hover:bg-[#006e5a] disabled:opacity-50">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4 21 12 3.4 3.6l-.02 6.53L15 12 3.38 13.87z"/></svg>
        </button>
      </form>

      <div data-chat-sheet hidden class="absolute inset-0 z-10 flex items-end bg-black/40">
        <div class="max-h-[80%] w-full overflow-y-auto rounded-t-2xl bg-ink pb-2" role="dialog" aria-modal="true" aria-labelledby="chat-sheet-title">
          <div class="sticky top-0 flex items-center gap-3 border-b border-line-soft bg-ink px-5 py-3.5">
            <button type="button" data-sheet-close aria-label="Cerrar" class="cursor-pointer text-xl leading-none text-dim hover:text-body">×</button>
            <b id="chat-sheet-title" data-sheet-title class="font-semibold"></b>
          </div>
          <div data-sheet-rows></div>
        </div>
      </div>
    </section>
  </div>
</header>

<section id="whatsapp" class="border-t border-line-soft py-16 sm:py-20">
  <div class="mx-auto grid w-full max-w-[1120px] items-center gap-10 px-6 lg:grid-cols-[1fr_minmax(0,580px)] lg:gap-16">
    <div>
      <span class="eyebrow">También por WhatsApp</span>
      <h2 class="sec-title mt-3 mb-4">Llevalo en el bolsillo.</h2>
      <p class="max-w-[34em] text-lg text-muted">
        Es el mismo bot que acabás de usar, en el chat que ya tenés abierto todo el día.
        Por WhatsApp, además, te puede escribir solo: pedile <b class="font-semibold text-body">«avisame EZE»</b>
        y te avisa cuando cambia el clima.
      </p>
    </div>

    <div id="empezar" class="w-full rounded-2xl border border-line bg-panel p-6 sm:p-8">
      <div class="mb-6 flex items-baseline justify-between gap-4">
        <h2 class="text-xl font-semibold tracking-[-0.015em]">Empezá en 30 segundos</h2>
        <span class="hidden font-mono text-xs text-dim sm:block">gratis · sin registro · sin app</span>
      </div>

      <ol class="grid gap-5">
        <li class="grid grid-cols-[28px_1fr] items-start gap-4">
          <span class="grid size-7 place-items-center rounded-lg border border-line bg-ink font-mono text-sm font-medium text-air">1</span>
          <p class="text-muted">
            Abrí el chat con
            <span class="rounded-md border border-line bg-ink px-2 py-0.5 font-mono text-[0.92em] font-medium whitespace-nowrap text-body">{{ $number ? '+'.$number : 'el bot' }}</span>
            desde el botón de acá abajo, o guardá el número en tu agenda.
          </p>
        </li>
        <li class="grid grid-cols-[28px_1fr] items-start gap-4">
          <span class="grid size-7 place-items-center rounded-lg border border-line bg-ink font-mono text-sm font-medium text-air">2</span>
          <p class="text-muted">
            Escribí el aeródromo: <strong class="font-semibold text-body">«EZE»</strong>,
            <strong class="font-semibold text-body">«SAEZ»</strong>,
            <strong class="font-semibold text-body">«Ezeiza»</strong> o directamente
            <strong class="font-semibold text-body">«¿hay notams en Aeroparque?»</strong>.
          </p>
        </li>
        <li class="grid grid-cols-[28px_1fr] items-start gap-4">
          <span class="grid size-7 place-items-center rounded-lg border border-line bg-ink font-mono text-sm font-medium text-air">3</span>
          <p class="text-muted">
            ¿Querés el clima? <strong class="font-semibold text-body">«metar EZE»</strong>.
            ¿El pronóstico? <strong class="font-semibold text-body">«taf EZE»</strong>.
            ¿Que te avise si cambia? <strong class="font-semibold text-body">«avisame EZE»</strong>.
          </p>
        </li>
      </ol>

      <div class="mt-8 flex flex-wrap gap-3">
        @if ($link)
          <a href="{{ $link }}" target="_blank" rel="noopener" class="btn bg-wa text-white hover:bg-[#0b5f33]">Abrir en WhatsApp →</a>
        @endif
        <a href="#chat" class="btn border border-line bg-ink hover:bg-panel-2">Probarlo acá</a>
      </div>
    </div>
  </div>
</section>

<section id="que-hace" class="border-t border-line-soft py-16 sm:py-24">
  <div class="mx-auto w-full max-w-[1120px] px-6">
    <div class="mb-12 max-w-[42em]">
      <span class="eyebrow">Qué le podés pedir</span>
      <h2 class="sec-title mt-3 mb-4">Ocho respuestas, un solo chat.</h2>
      <p class="text-lg text-muted">
        Escribís como hablás. El bot resuelve de qué aeródromo hablás y qué le estás
        preguntando; si el nombre es ambiguo —Córdoba tiene tres— pregunta en vez de
        elegir por vos.
      </p>
    </div>

    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
      <div class="card">
        <h3>NOTAM activos</h3>
        <p>Directo de ANAC, con la vigencia de cada uno y traducidos del telegrama a español corrido.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"hay notams en EZE?"</span></div>
      </div>
      <div class="card">
        <h3>METAR</h3>
        <p>La observación vigente del aeródromo: viento, visibilidad, nubes, temperatura y QNH, grupo por grupo.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"clima en Bariloche"</span></div>
      </div>
      <div class="card">
        <h3>TAF</h3>
        <p>El pronóstico de 24 a 30 h, con cada período (BECMG, TEMPO, PROB30, FM) explicado por separado.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"pronóstico de Aeroparque"</span></div>
      </div>
      <div class="card">
        <h3>PRONAREA</h3>
        <p>El pronóstico de área que emite el SMN por FIR, para cuando lo que importa es la ruta y no sólo el aeródromo de destino.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"pronarea EZE"</span></div>
      </div>
      <div class="card">
        <h3>AEROMET</h3>
        <p>Si el aeródromo no tiene METAR —o directamente no tiene código OACI—, la observación de la estación AEROMET más cercana, la misma red que también cubre localidades sin aeródromo.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"aeromet junín"</span></div>
      </div>
      <div class="card">
        <h3>Viento en pista</h3>
        <p>Componente a favor y cruzado para cada cabecera, calculado con el METAR —o el AEROMET si no hay— contra el rumbo real de cada pista.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"viento cruzado en Ezeiza"</span></div>
      </div>
      <div class="card">
        <h3>Crepúsculo</h3>
        <p>Salida y puesta del sol para cualquier aeródromo del país: la tabla del Servicio de Hidrografía Naval donde la publica, calculada sobre las coordenadas del aeródromo donde no.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"crepúsculo Santa Rosa"</span></div>
      </div>
      <div class="card">
        <h3>Alertas</h3>
        <p>El bot te escribe solo cuando el clima cambia de verdad. Un botón en cada METAR o una frase.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"avisame EZE"</span></div>
      </div>
    </div>

    <div class="mt-14 grid gap-10 lg:grid-cols-2 lg:gap-14">
      <div>
        <h3 class="mb-5 text-[1.15rem] font-semibold tracking-[-0.01em]">Entiende cómo escribís</h3>
        <div class="flex flex-wrap gap-2.5">
          <span class="chip">EZE</span>
          <span class="chip">SAEZ</span>
          <span class="chip">ezeiza</span>
          <span class="chip">hay notams en Ezeiza?</span>
          <span class="chip">viento en SAEZ</span>
          <span class="chip">cómo está el clima en Bariloche?</span>
          <span class="chip">va a llover en SAEZ?</span>
          <span class="chip">cómo va a estar el tiempo mañana?</span>
          <span class="chip">pronarea EZE</span>
          <span class="chip">aeromet junín</span>
          <span class="chip">qué pista conviene?</span>
          <span class="chip">crepúsculo Santa Rosa</span>
          <span class="chip">avisame EZE por 6 horas</span>
          <span class="chip">mis alertas</span>
          <span class="chip">baja EZE</span>
        </div>
      </div>
      <div>
        <h3 class="mb-5 text-[1.15rem] font-semibold tracking-[-0.01em]">Y desambigua en vez de adivinar</h3>
        <ul class="checks">
          <li><span><strong>Una pregunta sobre mañana</strong> se contesta con el pronóstico, no con la observación de ahora.</span></li>
          <li><span>Si escribiste <strong>«notam»</strong>, eso recibís: sabés lo que pediste.</span></li>
          <li><span>Si el nombre coincide con varios aeródromos, te muestra la lista y espera el código. Mandar los NOTAM del aeródromo equivocado es peor que repreguntar.</span></li>
          <li><span>Después de cada respuesta, un botón para pedir lo que sigue del mismo aeródromo —NOTAM, METAR, TAF o crepúsculo— sin volver a escribir el código.</span></li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section id="decodifica" class="border-t border-line-soft py-16 sm:py-24">
  <div class="mx-auto w-full max-w-[1120px] px-6">
    <div class="mb-12 max-w-[42em]">
      <span class="eyebrow">Decodificación</span>
      <h2 class="sec-title mt-3 mb-4">De telegrama a español.</h2>
      <p class="text-lg text-muted">
        El texto crudo nunca se omite: es la forma estándar internacional contra la
        que podés contrastar cualquier otra fuente. La explicación va debajo.
      </p>
    </div>

    <div class="overflow-hidden rounded-2xl border border-line">
      @foreach ([
        ['NOTAM', 'RWY 13/31 CLSD WIP MAINT', 'Pista 13/31 cerrada por trabajos de mantenimiento en progreso.'],
        ['METAR', '03009KT 9999 BKN008 15/14 Q1009', 'Viento del 030° (NNE) a 9 nudos. Visibilidad 10 km o más. Nubosidad rota (5 a 7 octavos) a 800 ft. Temperatura 15 °C, punto de rocío 14 °C. Presión QNH 1009 hPa.'],
        ['TAF', 'TEMPO 2808/2812 0500 FGDZ BKN005', 'Fluctuaciones temporarias el día 28 entre las 08:00 y las 12:00 UTC, en períodos de menos de una hora cada vez: visibilidad 500 m, niebla y llovizna, nubosidad rota a 500 ft.'],
      ] as $i => [$kind, $raw, $meaning])
        <div class="grid items-stretch md:grid-cols-[minmax(0,1fr)_48px_minmax(0,1.25fr)] {{ $i > 0 ? 'border-t border-line' : '' }}">
          <div class="flex min-w-0 flex-col justify-center border-b border-line bg-panel px-6 py-6 md:border-b-0">
            <span class="eyebrow mb-3 block">{{ $kind }}</span>
            <code class="font-mono text-[0.92rem] font-medium break-words text-raw">{{ $raw }}</code>
          </div>
          <div class="hidden place-items-center font-mono text-dim md:grid">→</div>
          <div class="flex min-w-0 flex-col justify-center px-6 py-6">
            <span class="eyebrow mb-3 block">Lo que significa</span>
            <p class="text-muted">{{ $meaning }}</p>
          </div>
        </div>
      @endforeach
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
      <div class="card">
        <h3>El crudo, siempre arriba</h3>
        <p>Cada respuesta empieza por el reporte tal como se emitió. La explicación va debajo, y podés ignorarla: nunca reemplaza al texto que estás acostumbrado a leer.</p>
      </div>
      <div class="card">
        <h3>Nada se inventa</h3>
        <p>Un grupo que no se reconoce aparece marcado como tal, nunca traducido a lo que suena razonable ni omitido en silencio. Si algo no está explicado, se ve.</p>
      </div>
      <div class="card">
        <h3>Siempre el vigente</h3>
        <p>Un METAR queda superado en cuanto sale el siguiente, así que recibís ese y no una lista vieja. Del TAF, además, te avisa si viene enmendado (AMD) o cancelado (CNL).</p>
      </div>
      <div class="card">
        <h3>Aeródromo cerrado, dicho de entrada</h3>
        <p>Si el aeródromo está cerrado, el bot lo pone en el encabezado del NOTAM, no como un ítem más de la lista — para que no se lea como «está todo bien» cuando no lo está.</p>
      </div>
    </div>

    <div class="mt-14 grid gap-5 lg:grid-cols-2">
      <div class="card p-7">
        <h3>METAR y TAF no son lo mismo</h3>
        <p>
          Un METAR es una <strong class="font-semibold text-body">observación</strong>: qué está pasando
          ahora en el aeródromo. Un TAF es un <strong class="font-semibold text-body">pronóstico</strong>:
          qué se espera en las próximas 24 o 30 horas. Por eso son dos respuestas distintas, y una
          pregunta sobre mañana nunca se contesta con la observación de recién.
        </p>
      </div>
      <div class="card p-7">
        <h3>El TEMPO, dicho como es</h3>
        <p>
          Un TAF no es una foto sino una secuencia: <code>BECMG</code>, <code>TEMPO</code>,
          <code>PROB30</code> y <code>FM</code> abren cada uno su ventana, y lo que sigue vale sólo
          dentro de ella. Cada cambio se explica por separado, con su horario, y el <code>TEMPO</code>
          aclara que las condiciones pueden aparecer por menos de una hora cada vez — no durante toda
          la ventana.
        </p>
      </div>
    </div>
  </div>
</section>

<section id="alertas" class="border-t border-line-soft py-16 sm:py-24">
  <div class="mx-auto w-full max-w-[1120px] px-6">
    <div class="mb-12 max-w-[42em]">
      <span class="eyebrow">Alertas</span>
      <h2 class="sec-title mt-3 mb-4">«Avisame si cambia» — y sólo si cambia.</h2>
      <p class="text-lg text-muted">
        El METAR se reemite cada hora y casi siempre difiere del anterior: un grado de
        temperatura, dos nudos de viento. Avisar por eso sería un despertador horario,
        así que la comparación se hace sobre los grupos que un piloto efectivamente acciona.
      </p>
    </div>

    <div class="grid gap-10 lg:grid-cols-2 lg:gap-14">
      <div>
        <h3 class="mb-5 text-[1.15rem] font-semibold">Qué cuenta como un cambio</h3>
        <ul class="checks">
          <li><span>La llegada de un <strong>SPECI</strong> — lo emitió el SMN porque algo cruzó un umbral, y ese criterio manda.</span></li>
          <li><span><strong>Categoría de vuelo</strong> (VFR / MVFR / IFR / LIFR), siempre por el peor entre techo y visibilidad.</span></li>
          <li><span><strong>Viento</strong>: ±10 kt, aparición o desaparición de ráfaga, o ±30° de dirección — pero sólo con 10 kt o más, porque abajo de eso la dirección deambula sola.</span></li>
          <li><span><strong>Visibilidad y techo</strong> que cruzan una banda (800 / 1500 / 3000 / 5000 / 8000 m; 200 / 500 / 1000 / 1500 / 3000 ft).</span></li>
          <li><span><strong>Fenómenos</strong> que empiezan, terminan o cambian de intensidad (<code>-RA</code> → <code>+TSRA</code>).</span></li>
          <li><span><strong>QNH</strong> de ±2 hPa.</span></li>
        </ul>
      </div>
      <div>
        <h3 class="mb-5 text-[1.15rem] font-semibold">Cómo se manejan</h3>
        <div class="mb-7 flex flex-wrap gap-2.5">
          <span class="chip">avisame EZE</span>
          <span class="chip">avisame EZE por 6 horas</span>
          <span class="chip">mis alertas</span>
          <span class="chip">baja EZE</span>
          <span class="chip">baja todas</span>
        </div>
        <ul class="checks">
          <li><span>Cada METAR trae un botón <strong>Avisarme 12 h</strong>, y cada aviso uno de <strong>Dar de baja</strong>. Escrito funciona igual.</span></li>
          <li><span>Duran <strong>12 horas</strong> por defecto y 24 como máximo. Volver a suscribirte la renueva.</span></li>
          <li><span>Hasta <strong>5 aeródromos</strong> a la vez, y el bot los revisa cada diez minutos.</span></li>
          <li><span>Cuando una alerta vence te lo dice. Una que simplemente dejara de escribir sería idéntica a una que no tuvo nada que informar — y «el clima no cambió» es justo lo que alguien podría dar por sentado.</span></li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section id="fuentes" class="border-t border-line-soft py-16 sm:py-24">
  <div class="mx-auto w-full max-w-[1120px] px-6">
    <div class="mb-12 max-w-[42em]">
      <span class="eyebrow">De dónde sale</span>
      <h2 class="sec-title mt-3 mb-4">Los mismos partes que consultás.</h2>
      <p class="text-lg text-muted">
        Nada de acá se elabora: son los NOTAM que publica ANAC y los METAR y TAF que
        emite el Servicio Meteorológico Nacional, tal como salieron. Lo único que agrega
        el bot es la lectura en español debajo del texto.
      </p>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
      <div class="card">
        <h3>NOTAM — ANAC</h3>
        <p>Los NOTAM activos del aeródromo, con la fecha desde la que rigen y hasta cuándo, o si son permanentes.</p>
      </div>
      <div class="card">
        <h3>METAR y TAF — SMN</h3>
        <p>La observación y el pronóstico de aeródromo emitidos por el Servicio Meteorológico Nacional, que es la autoridad para los aeródromos argentinos.</p>
      </div>
      <div class="card">
        <h3>Si el SMN no responde</h3>
        <p>El reporte llega igual por el intercambio internacional OPMET: es el mismo que emitió el SMN, retransmitido. Cada mensaje aclara al pie cuándo pasó por ahí.</p>
      </div>
    </div>
  </div>
</section>

<section class="border-t border-line-soft py-16 sm:py-24">
  <div class="mx-auto w-full max-w-[1120px] px-6">
    <div class="rounded-2xl border border-line bg-panel px-6 py-12 text-center sm:px-10">
      <img src="/images/flybot-logo.webp" alt="FlyBot" width="104" height="104" class="mx-auto mb-6 size-26">
      <h2 class="text-[clamp(1.7rem,3vw,2.2rem)] font-semibold tracking-[-0.025em]">Probalo con tu aeródromo.</h2>
      <p class="mx-auto mt-4 mb-8 max-w-[52ch] text-lg text-muted">
        Escribile «EZE» —o cualquier aeródromo de Argentina— y ves la respuesta completa,
        acá o en WhatsApp. Gratis, sin registro y sin instalar nada.
      </p>
      <div class="flex flex-wrap justify-center gap-3">
        <a href="#chat" class="btn bg-wa-head text-white hover:bg-[#006e5a]">Escribirle acá ↑</a>
        @if ($link)
          <a href="{{ $link }}" target="_blank" rel="noopener" class="btn border border-line bg-ink hover:bg-panel-2">Abrir en WhatsApp →</a>
        @endif
      </div>
    </div>
  </div>
</section>

@include('partials.footer')

</body>
</html>

@php($home = $home ?? '')

<footer class="border-t border-line py-12">
  <div class="mx-auto flex w-full max-w-[1120px] flex-wrap items-center justify-between gap-5 px-6">
    <div class="grid gap-1.5 text-[0.9rem] text-dim">
      <p>
        Hecho por <span class="font-medium text-body">Francisco Dadone</span> —
        <a href="mailto:dadonefran@gmail.com" class="text-air hover:underline">dadonefran@gmail.com</a>
      </p>
      <p>
        Ideado por <span class="font-medium text-body">Gabriel Jalabert</span> —
        <a href="mailto:gabyjalabert@gmail.com" class="text-air hover:underline">gabyjalabert@gmail.com</a>
      </p>
      <p>
        NOTAM de <a href="https://ais.anac.gob.ar" target="_blank" rel="noopener" class="text-air hover:underline">ANAC</a> ·
        METAR y TAF del <a href="https://www.smn.gob.ar/metar" target="_blank" rel="noopener" class="text-air hover:underline">Servicio Meteorológico Nacional</a>.
      </p>
    </div>
    <div class="flex flex-wrap gap-x-6 gap-y-3 text-[0.9rem] text-muted">
      <a href="{{ $home }}#empezar" class="hover:text-body">Empezar</a>
      <a href="{{ $home }}#que-hace" class="hover:text-body">Qué le pedís</a>
      <a href="{{ $home }}#alertas" class="hover:text-body">Alertas</a>
      <a href="/privacidad" class="hover:text-body">Privacidad</a>
      <a href="https://www.instagram.com/flybot.ar/" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 hover:text-body">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="0.5" fill="currentColor"/>
        </svg>
        @flybot.ar
      </a>
    </div>
  </div>
</footer>

<script>
  console.log(
    '%c✈ FlyBot%c\n\n¿Curioseando el código? FlyBot es open source.\nSumate a mejorarlo: https://github.com/FranciscoDadone/FLYBOT',
    'font-size:20px;font-weight:600;color:#0b5fd0',
    'font-size:13px'
  );
</script>

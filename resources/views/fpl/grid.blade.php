{{--
    The calibration overlay: the blank form under a pixel ruler, with every
    field and struck box drawn where the layout says it goes. Nothing here is
    ever sent to anyone -- it exists so that "move the level down eight pixels"
    is something you can see rather than guess.
--}}
<style>
    @page {
        margin: 0;
        background-image: url("{{ $background }}");
        background-image-resize: 6;
    }

    body { margin: 0; font-family: dejavusanscondensed; }

    div.v { position: absolute; top: 0; width: 0.1mm; height: 297mm; background: #f0a; }
    div.h { position: absolute; left: 0; height: 0.1mm; width: 210mm; background: #f0a; }
    div.t { position: absolute; font-size: 4pt; color: #f0a; }
    div.b { position: absolute; border: 0.2mm solid #06c; font-size: 4pt; color: #06c; }
    div.s { position: absolute; border: 0.2mm solid #0a0; }
</style>

@foreach ($rulers['v'] as $r)
    <div class="v" style="left: {{ $r['at'] }}mm"></div>
    <div class="t" style="left: {{ $r['at'] }}mm; top: 1mm">{{ $r['px'] }}</div>
@endforeach

@foreach ($rulers['h'] as $r)
    <div class="h" style="top: {{ $r['at'] }}mm"></div>
    <div class="t" style="left: 0.5mm; top: {{ $r['at'] }}mm">{{ $r['px'] }}</div>
@endforeach

@foreach ($fields as $name => $box)
    <div class="b" style="left: {{ $box['left'] }}mm; top: {{ $box['top'] }}mm; width: {{ $box['width'] }}mm; height: {{ $box['height'] }}mm">{{ $name }}</div>
@endforeach

@foreach ($strikes as $box)
    <div class="s" style="left: {{ $box['left'] }}mm; top: {{ $box['top'] }}mm; width: {{ $box['width'] }}mm; height: {{ $box['height'] }}mm"></div>
@endforeach

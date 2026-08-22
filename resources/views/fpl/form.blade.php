{{--
    The EANA flight plan, drawn as text on top of the blank form.

    Every position comes from resources/data/fpl-layout.php in the pixels of
    that scan; the renderer converts to millimetres. Nothing here decides where
    anything goes, so recalibrating the form is a data change and never a
    template one.

    The whole page is uppercase: the form is filled in capitals, and forcing it
    here as well as in the renderer means a lowercase value can never reach the
    paper by way of a field nobody remembered to normalise.
--}}
<style>
    @page {
        margin: 0;
        background-image: url("{{ $background }}");
        background-image-resize: 6;
    }

    body {
        margin: 0;
        font-family: dejavuserifcondensed;
        text-transform: uppercase;
        color: #000;
    }

    div.f {
        position: absolute;
        overflow: hidden;
    }

    div.x {
        position: absolute;
        background: #000;
    }
</style>

@foreach ($fields as $box)
    <div class="f" style="
        left: {{ $box['left'] }}mm;
        top: {{ $box['top'] }}mm;
        width: {{ $box['width'] }}mm;
        font-size: {{ $box['size'] }}pt;
        line-height: {{ $box['leading'] }}mm;
        text-align: {{ $box['align'] }};
        @if ($box['spacing'] > 0) letter-spacing: {{ $box['spacing'] }}mm; @endif
    ">{{ $box['value'] }}</div>
@endforeach

@foreach ($strikes as $box)
    {{-- What is NOT carried. Filled solid, the way the paper form marks it. --}}
    <div class="x" style="
        left: {{ $box['left'] }}mm;
        top: {{ $box['top'] }}mm;
        width: {{ $box['width'] }}mm;
        height: {{ $box['height'] }}mm;
    "></div>
@endforeach

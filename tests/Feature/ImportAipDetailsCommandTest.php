<?php

use App\Models\Airport;
use Illuminate\Support\Facades\Http;

/**
 * This is where notams:import-aip-details actually reaches through
 * AipService and AipAdDetails end to end — the download, the real PDF bytes,
 * the parse, the upsert — which AipAdDetailsTest never does, since it works
 * from text already extracted.
 */
beforeEach(function () {
    // The seed data is only what notams:import-madhel would have written —
    // is_aip_delegated is notams:import-airport-details' own column, and this
    // suite never runs that import, so it has to be set by hand. ELP has no
    // ICAO code in the seed, which is what stands in here for "delegated but
    // the AIP listing has nothing to look it up by".
    Airport::query()->whereNotIn('anac_code', ['OSA', 'CIF', 'EZE', 'ELP'])->delete();
    Airport::whereIn('anac_code', ['OSA', 'EZE', 'ELP'])->update(['is_aip_delegated' => true]);
});

it('imports the AIP ficha of an aerodrome delegated to it', function () {
    fakeAipDocuments(['OSA']);

    $this->artisan('notams:import-aip-details --only=OSA')->assertSuccessful();

    $airport = Airport::where('anac_code', 'OSA')->firstOrFail();

    expect($airport->aip_fuel)->toBe('AVGAS 100LL y/and JET A-1')
        ->and($airport->aip_telephone)->toBe([
            '(+54 2954) 434690',
            '(+54 2954) 434490',
            '(+54 9 2954) 506705',
            '(+54 9 2954) 506740',
        ])
        ->and($airport->aip_service_schedule)->toBe('LUN a VIE 12:00 a 23:00 UTC. SÁB 17:00 a 23:00 UTC. DOM 13:00 a 21:00 UTC')
        ->and($airport->aip_ats_frequency)->toBe('TWR/APP SANTA ROSA TORRE — 118.30 MHz (CPPL) · 119.70 MHz (CAUX)')
        ->and($airport->aip_navaids)->toBe([
            ['type' => 'VOR/DME', 'id' => 'OSA', 'frequency' => '112.5', 'unit' => 'MHz', 'hours' => 'H24'],
            ['type' => 'ILS/LOC', 'id' => 'SR', 'frequency' => '110.3', 'unit' => 'MHz', 'hours' => 'H24'],
        ])
        ->and($airport->aip_details_updated_at)->not->toBeNull();
});

/**
 * CIF has no ICAO code, and the AIP indexes its fichas by nothing else, so
 * there is no document to read for it and its AIP columns must stay null —
 * the same skip a delegated aerodrome with no document gets.
 */
it('leaves an aerodrome the AIP publishes no ficha for untouched', function () {
    fakeAipDocuments(['OSA']);

    $this->artisan('notams:import-aip-details')->assertSuccessful();

    $airport = Airport::where('anac_code', 'CIF')->firstOrFail();

    expect($airport->aip_fuel)->toBeNull()
        ->and($airport->aip_details_updated_at)->toBeNull();
});

/**
 * The AIP's own listing does not carry a document for every delegated
 * aerodrome — General Pico (GPI) has none at the time this was written — and
 * that has to be a skip, not a failure that takes the rest of the batch with
 * it.
 */
it('skips a delegated aerodrome the AIP listing has no document for', function () {
    fakeAipDocuments(['OSA']);

    $this->artisan('notams:import-aip-details --only=OSA,ELP')->assertSuccessful();

    expect(Airport::where('anac_code', 'OSA')->value('aip_details_updated_at'))->not->toBeNull()
        ->and(Airport::where('anac_code', 'ELP')->value('aip_details_updated_at'))->toBeNull();
});

it('imports the rest when one PDF fails to download', function () {
    fakeAipDocuments(['OSA', 'EZE'], ['EZE' => Http::response('down', 503)]);

    $this->artisan('notams:import-aip-details --only=OSA,EZE')->assertSuccessful();

    expect(Airport::where('anac_code', 'OSA')->value('aip_fuel'))->toBe('AVGAS 100LL y/and JET A-1')
        ->and(Airport::where('anac_code', 'EZE')->value('aip_details_updated_at'))->toBeNull();
});

it('aborts rather than import a truncated AIP listing', function () {
    fakeAipDocuments(['OSA'], ['listing' => Http::response('<table id="datatable"></table>', 200)]);
    config(['services.aip.minimum_ad_documents' => 30]);

    $this->artisan('notams:import-aip-details --only=OSA')->assertFailed();

    expect(Airport::where('anac_code', 'OSA')->value('aip_details_updated_at'))->toBeNull();
});

/**
 * The columns each import owns are disjoint on purpose, same as MADHEL's own
 * imports: this one must never touch fuel/telephone/service_schedule — those
 * are notams:import-airport-details' columns, read from MADHEL — nor the
 * columns any other import owns.
 */
it('leaves the columns the other imports own alone', function () {
    Airport::where('anac_code', 'OSA')->update([
        'name' => 'SANTA ROSA',
        'fuel' => null,
        'elevation_m' => 192,
    ]);

    fakeAipDocuments(['OSA']);

    $this->artisan('notams:import-aip-details --only=OSA')->assertSuccessful();

    $airport = Airport::where('anac_code', 'OSA')->firstOrFail();

    expect($airport->name)->toBe('SANTA ROSA')
        ->and($airport->fuel)->toBeNull()
        ->and($airport->elevation_m)->toBe(192);
});

/**
 * Morón (SADM) is not delegated — MADHEL publishes its fuel and telephone
 * itself — and the AIP still publishes an AD 2 for it carrying the tower
 * frequency and the radio aids, which nothing else does. Reading it is the
 * whole point of walking documents rather than the delegation flag; EZE
 * stands in for it here.
 */
it('imports the AIP ficha of an aerodrome that is not delegated but has an AD 2 document', function () {
    Airport::where('anac_code', 'EZE')->update(['is_aip_delegated' => false]);
    fakeAipDocuments(['EZE']);

    $this->artisan('notams:import-aip-details')->assertSuccessful();

    expect(Airport::where('anac_code', 'EZE')->value('aip_ats_frequency'))
        ->toBe('TWR/APP SANTA ROSA TORRE — 118.30 MHz (CPPL) · 119.70 MHz (CAUX)');
});

it('says so rather than failing when the registry has no aerodrome the AIP publishes a ficha for', function () {
    Airport::query()->update(['is_aip_delegated' => false, 'icao_code' => null]);
    fakeAipDocuments(['OSA']);

    $this->artisan('notams:import-aip-details')
        ->expectsOutputToContain('notams:import-airport-details')
        ->assertSuccessful();
});

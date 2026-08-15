<?php

namespace App\Services;

/**
 * NOAA's relay of the SMN's forecasts — see NoaaReportSource.
 */
class NoaaTafSource extends NoaaReportSource
{
    protected function endpoint(): string
    {
        return 'taf';
    }

    protected function rawField(): string
    {
        return 'rawTAF';
    }

    /**
     * No window at all. /api/data/taf rejects "hours" with a 400 ("Unexpected
     * query parameter provided") — unlike the METAR endpoint, which still takes
     * it — and answers with the forecast in force on its own, which is the only
     * one this service keeps anyway.
     */
    protected function hours(): ?int
    {
        return null;
    }
}

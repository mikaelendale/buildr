<?php

use App\Services\Ai\SummaryGenerator;

it('packs post fields into one pipe-separated line', function () {
    expect(SummaryGenerator::line([42, '2026-05-02', 'project_launch', "Shipped v1 | finally\nlive", null]))
        ->toBe('42 | 2026-05-02 | project_launch | Shipped v1 finally live | ');
});

<?php

namespace App\Console\Commands;

use App\Services\Claims\ClaimService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('buildr:repair-claims')]
#[Description('Attach channels whose claim was verified but never attached')]
class RepairClaims extends Command
{
    public function handle(ClaimService $claims): int
    {
        $repaired = $claims->repairUnattached();

        $this->info($repaired === [] ? 'Nothing to repair.' : 'Repaired: @'.implode(', @', $repaired));

        return self::SUCCESS;
    }
}

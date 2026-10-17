<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\Payload;

interface Action
{
    /**
     * Validates then mutates the farm; throws GameError on any rule violation.
     */
    public function apply(Farm $farm, Payload $payload, Clock $clock): void;
}

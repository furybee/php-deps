<?php

declare(strict_types=1);

namespace DepsGraph\Architecture\Reporter;

use DepsGraph\Architecture\CheckResult;

interface Reporter
{
    public function report(CheckResult $result): string;
}

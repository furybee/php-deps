<?php

namespace Shop\Billing\Domain;

use Shop\Billing\Infrastructure\PdfRenderer;
use Shop\Legacy\Helpers;

final class Invoice
{
    public function render(): string
    {
        Helpers::format();

        return (new PdfRenderer())->render($this);
    }
}

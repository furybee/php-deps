<?php

namespace Shop\Billing\Infrastructure;

use Shop\Billing\Domain\Invoice;

final class PdfRenderer
{
    public function render(Invoice $invoice): string
    {
        return '';
    }
}

<?php

namespace Shop\Billing\Application;

use Shop\Billing\Domain\Invoice;

final class IssueInvoice
{
    public function execute(Invoice $invoice): string
    {
        return $invoice->render();
    }
}

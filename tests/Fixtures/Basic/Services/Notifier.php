<?php

namespace Acme\Services;

use Acme\Models\Status;

final class Notifier
{
    public function notify(Mailer $mailer): string
    {
        try {
            $mailer->send();
        } catch (MailerException) {
        }

        return Status::Active->value;
    }
}

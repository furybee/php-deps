<?php

namespace Acme\Models;

use Acme\Services\Mailer;

class User extends Model
{
    use HasProfile;

    /** @var Mailer */
    public $mailer;

    public function notify(): void
    {
    }
}

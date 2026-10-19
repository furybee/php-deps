<?php

namespace Acme\Models;

abstract class Model extends \Illuminate\Database\Eloquent\Model
{
    public function refresh(): static
    {
        return $this;
    }
}

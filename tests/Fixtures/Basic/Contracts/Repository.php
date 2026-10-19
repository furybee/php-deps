<?php

namespace Acme\Contracts;

interface Repository
{
    public function find(int $id): mixed;

    public function report(): \Acme\Models\Report;
}

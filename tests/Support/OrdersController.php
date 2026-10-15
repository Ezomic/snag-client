<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient\Tests\Support;

use Illuminate\Support\Facades\DB;

class OrdersController
{
    public function show(): string
    {
        DB::select('select 1');
        DB::select('select 2');

        return 'ok';
    }
}

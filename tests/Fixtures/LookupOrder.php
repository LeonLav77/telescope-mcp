<?php

namespace LloydsDigital\TelescopeMcp\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class LookupOrder extends Model
{
    protected $table = 'lookup_orders';

    protected $guarded = [];

    public $timestamps = false;
}

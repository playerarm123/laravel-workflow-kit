<?php

namespace App\Infra\Time;

use App\Domain\Shared\Ports\Clock;
use Illuminate\Support\ServiceProvider;

class TimeServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        Clock::class => SystemClock::class,
    ];
}

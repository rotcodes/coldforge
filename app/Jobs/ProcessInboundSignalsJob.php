<?php

namespace App\Jobs;

use App\Services\Deliverability\InboundSignalProcessor;
use App\Services\Deliverability\SendingHealthMonitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInboundSignalsJob implements ShouldQueue
{
    use Queueable;

    public function handle(InboundSignalProcessor $signals, SendingHealthMonitor $health): void
    {
        $signals->process();
        $health->evaluate();
    }
}

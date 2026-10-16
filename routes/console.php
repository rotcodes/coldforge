<?php

use App\Jobs\DispatchDueCampaignsJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new DispatchDueCampaignsJob)->everyMinute();

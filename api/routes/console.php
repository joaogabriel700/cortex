<?php

use Illuminate\Support\Facades\Schedule;

/*
| Business tasks are NOT scheduled here: register them in App\Providers\RecurringTaskServiceProvider.
| `app:tick` runs whatever became due since its last successful run, so a late or missed
| trigger does not skip anything. Always-on hosts call it every minute through this entry.
*/
Schedule::command('app:tick')->everyMinute()->withoutOverlapping(10);

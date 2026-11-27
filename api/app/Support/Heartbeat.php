<?php

namespace App\Support;

/** Each container has its own /tmp; the Compose health checks look at this file's age. */
class Heartbeat
{
    public static function beat(): void
    {
        @touch(sys_get_temp_dir().'/heartbeat');
    }
}

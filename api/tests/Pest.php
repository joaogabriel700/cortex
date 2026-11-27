<?php

use Tests\TestCase;

/*
| RefreshDatabase is not used: it would run the tests inside a transaction, as the connected
| role. The schema is migrated beforehand by app_owner and the tests run as app_runtime.
*/
pest()->extend(TestCase::class)->in('Feature');

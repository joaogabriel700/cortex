<?php

return [

    'driver' => 'bcrypt',

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

    // Signing in never rewrites the password hash.
    'rehash_on_login' => false,

];

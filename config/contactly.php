<?php

$frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend URLs
    |--------------------------------------------------------------------------
    |
    | The reset URL is embedded in password reset emails. It should point to
    | the page in the separate React frontend that collects the token and
    | submits it to the API. Authenticated password changes are handled
    | directly through the API and do not use email links.
    |
    */

    'frontend' => [
        'password_reset_url' => rtrim(
            env('FRONTEND_PASSWORD_RESET_URL', $frontendUrl.'/reset-password'),
            '/'
        ),
    ],

];

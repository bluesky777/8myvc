<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    /*
     * El proxy de IA: `myvc-ia-proxy`, que es quien tiene la clave de Anthropic.
     * Aquí sólo hay a dónde llamar y con qué secreto firma ESTE colegio. Sin las
     * dos, el controlador contesta 503 y la pantalla esconde el botón.
     */
    'ia' => [
        'url' => env('MYVC_IA_URL'),
        'secreto' => env('MYVC_IA_SECRETO'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];

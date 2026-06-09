<?php

return [
    'asp_id' => env('ESIGN_ASP_ID', 'NDIT-900'),

    'endpoint' => env('ESIGN_ENDPOINT', 'https://es-staging.cdac.in/esignlevel2/2.1/form/signdoc'),

    'private_key' => env('ESIGN_PRIVATE_KEY', 'keys/eSign_Staging_Private.key'),

    'private_key_passphrase' => env('ESIGN_PRIVATE_KEY_PASSPHRASE', ''),

    'response_url' => env('ESIGN_RESPONSE_URL', env('APP_URL').'/esign/response'),

    'auth_mode' => env('ESIGN_AUTH_MODE', '1'),

    'version' => env('ESIGN_VERSION', '2.1'),

    'response_signature_type' => env('ESIGN_RESPONSE_SIGNATURE_TYPE', 'pkcs7'),

    'timestamp_timezone' => env('ESIGN_TIMESTAMP_TIMEZONE', 'Asia/Kolkata'),

    'storage_disk' => env('ESIGN_STORAGE_DISK', 'local'),

    'storage_path' => env('ESIGN_STORAGE_PATH', 'esign'),

    'signature_appearance' => [
        'x' => (float) env('ESIGN_SIGNATURE_X', 140),
        'y' => (float) env('ESIGN_SIGNATURE_Y', 255),
        'width' => (float) env('ESIGN_SIGNATURE_WIDTH', 60),
        'height' => (float) env('ESIGN_SIGNATURE_HEIGHT', 17),
    ],
];

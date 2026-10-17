<?php

declare(strict_types=1);

// Copy to config/config.php and fill in real values. config.php is not versioned.

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'ferme_tranquille',
        'user' => 'ferme',
        'password' => 'change-me',
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name' => 'ft_session',
        'lifetime' => 60 * 60 * 24 * 30, // 30 days
        'secure' => true, // set false only for local http://localhost
    ],
    'app' => [
        'timezone' => 'Europe/Paris',
        'debug' => false,
    ],
];

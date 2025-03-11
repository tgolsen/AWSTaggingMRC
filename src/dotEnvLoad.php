<?php

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

if (empty($requiredEnvVars)) {
    $requiredEnvVars = [];
}

foreach ($requiredEnvVars as $envVar) {
    if (empty($_ENV[$envVar])) {
        die("Error: Required environment variable '$envVar' is missing or empty in the .env file.\n");
    }
}

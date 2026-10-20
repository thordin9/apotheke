<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->add('healthz', '\\Apotheke\\Core\\Controllers\\HealthController::healthz');
$routes->add('health', '\\Apotheke\\Core\\Controllers\\HealthController::health');

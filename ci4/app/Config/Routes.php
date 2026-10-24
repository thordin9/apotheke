<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('healthz', '\\Apotheke\\Core\\Controllers\\HealthController::healthz');
$routes->get('health', '\\Apotheke\\Core\\Controllers\\HealthController::health');

// Public branding (Settings module). GET; HEAD is answered like GET.
$routes->match(['GET', 'HEAD'], 'theme.css', '\\Apotheke\\Settings\\Controllers\\BrandingController::theme');
$routes->match(['GET', 'HEAD'], 'labels.css', '\\Apotheke\\Settings\\Controllers\\BrandingController::labels');
$routes->match(['GET', 'HEAD'], 'manifest.webmanifest', '\\Apotheke\\Settings\\Controllers\\BrandingController::manifest');
$routes->match(['GET', 'HEAD'], 'favicon.ico', '\\Apotheke\\Settings\\Controllers\\BrandingController::favicon');
$routes->match(['GET', 'HEAD'], 'brand/([a-z0-9.\-]+)', '\\Apotheke\\Settings\\Controllers\\BrandingController::brand/$1');

<?php

use CodeIgniter\Router\RouteCollection;

/**
 * Every route records the permission legacy route_perm() gives it (`permission`
 * option). The required Permission filter (Auth) reads it from the route the
 * router matches; a route without one is refused to everyone. `none`: legacy
 * answers it before its permission check.
 *
 * @var RouteCollection $routes
 */
$none = ['permission' => 'none'];
$routes->get('healthz', '\\Apotheke\\Core\\Controllers\\HealthController::healthz', $none);
$routes->get('health', '\\Apotheke\\Core\\Controllers\\HealthController::health', $none);

// Public branding (Settings module). GET; HEAD is answered like GET.
$routes->match(['GET', 'HEAD'], 'theme.css', '\\Apotheke\\Settings\\Controllers\\BrandingController::theme', $none);
$routes->match(['GET', 'HEAD'], 'labels.css', '\\Apotheke\\Settings\\Controllers\\BrandingController::labels', $none);
$routes->match(['GET', 'HEAD'], 'manifest.webmanifest', '\\Apotheke\\Settings\\Controllers\\BrandingController::manifest', $none);
$routes->match(['GET', 'HEAD'], 'favicon.ico', '\\Apotheke\\Settings\\Controllers\\BrandingController::favicon', $none);
$routes->match(['GET', 'HEAD'], 'brand/([a-z0-9.\-]+)', '\\Apotheke\\Settings\\Controllers\\BrandingController::brand/$1', $none);

// Layout: the menu's Auto / Light / Dark.
$routes->post('theme', '\\Apotheke\\Layout\\Controllers\\ThemeController::save', ['permission' => 'view']);

// Autofill client. POST only.
$routes->post('autofill/suggest', '\\Apotheke\\Autofill\\Controllers\\SuggestController::suggest', ['permission' => 'create']);
$routes->post('settings/autofill/token', '\\Apotheke\\Autofill\\Controllers\\SettingsAutofillController::token', ['permission' => 'settings']);
$routes->post('settings/autofill/test', '\\Apotheke\\Autofill\\Controllers\\SettingsAutofillController::test', ['permission' => 'settings']);
$routes->post('settings/autofill/test-photo', '\\Apotheke\\Autofill\\Controllers\\SettingsAutofillController::testPhoto', ['permission' => 'settings']);

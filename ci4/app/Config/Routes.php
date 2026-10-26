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

// Media: served photos and thumbnails, never an original (legacy serve_image()).
$routes->match(['GET', 'HEAD'], 'media/(full|thumb)/([^/]+)', '\\Apotheke\\Media\\Controllers\\MediaController::serve/$1/$2', ['permission' => 'view']);

// Layout: the menu's Auto / Light / Dark.
$routes->post('theme', '\\Apotheke\\Layout\\Controllers\\ThemeController::save', ['permission' => 'view']);

// Autofill client. POST only.
$routes->post('autofill/suggest', '\\Apotheke\\Autofill\\Controllers\\SuggestController::suggest', ['permission' => 'create']);
$routes->post('settings/autofill/token', '\\Apotheke\\Autofill\\Controllers\\SettingsAutofillController::token', ['permission' => 'settings']);
$routes->post('settings/autofill/test', '\\Apotheke\\Autofill\\Controllers\\SettingsAutofillController::test', ['permission' => 'settings']);
$routes->post('settings/autofill/test-photo', '\\Apotheke\\Autofill\\Controllers\\SettingsAutofillController::testPhoto', ['permission' => 'settings']);

// Taxonomy: /locations and /categories (Manage). GET needs view, POST edit.
// The entry's other paths answer "<One> not found." or "Page not found." as legacy.
$manage = '\\Apotheke\\Taxonomy\\Controllers\\ManageController';
foreach (['locations', 'categories'] as $segment) {
    $routes->get($segment, $manage . '::index/' . $segment, ['permission' => 'view']);
    $routes->post($segment, $manage . '::create/' . $segment, ['permission' => 'edit']);
    $routes->get($segment . '/([0-9]+)/delete', $manage . '::confirmDelete/' . $segment . '/$1', ['permission' => 'view']);
    $routes->post($segment . '/([0-9]+)/(rename|default|delete|parent)', $manage . '::act/' . $segment . '/$1/$2', ['permission' => 'edit']);
    $routes->get($segment . '/([0-9]+)', $manage . '::notFound/' . $segment . '/$1', ['permission' => 'view']);
    $routes->get($segment . '/([0-9]+)/(rename|default|parent)', $manage . '::notFound/' . $segment . '/$1', ['permission' => 'view']);
    $routes->post($segment . '/([0-9]+)', $manage . '::notFound/' . $segment . '/$1', ['permission' => 'edit']);
}

// Auth: sign-in, registration and sign-out (legacy answers these before its permission
// check and checks the CSRF token itself, so they are `none` and skip the global CSRF
// filter, Config\Filters), then your own account (`self`). Legacy shows these three pages
// for every method but POST, so they take every method CI4 routes (add(): also OPTIONS,
// TRACE and CONNECT; N-63, N-353); on /account a non-safe one still needs the CSRF token
// (SEC-N10). Methods CI4 cannot route (WebDAV PROPFIND and the like) get 404.
$signIn = '\\Apotheke\\Auth\\Controllers\\SignInController';
$routes->add('login', $signIn . '::login', $none);
$routes->add('register', $signIn . '::register', $none);
$routes->post('logout', $signIn . '::logout', $none);
$routes->add('account', '\\Apotheke\\Auth\\Controllers\\AccountController::index', ['permission' => 'self']);
$routes->post('account/signout', '\\Apotheke\\Auth\\Controllers\\AccountController::signOutEverywhere', ['permission' => 'self']);

// Catalog: the list and search, the overview, saved filters. GET (and HEAD) needs view, POST edit.
$catalog = '\\Apotheke\\Catalog\\Controllers\\';
$routes->match(['GET', 'HEAD'], '/', $catalog . 'ListController::index', ['permission' => 'view']);
$routes->match(['GET', 'HEAD'], 'stats', $catalog . 'ListController::stats', ['permission' => 'view']);
$routes->match(['GET', 'HEAD'], 'filters', $catalog . 'FilterController::index', ['permission' => 'view']);
$routes->post('filters', $catalog . 'FilterController::create', ['permission' => 'edit']);
$routes->post('filters/([0-9]+)/(rename|delete)', $catalog . 'FilterController::act/$1/$2', ['permission' => 'edit']);

// Favourites: the star needs edit; clearing this device's recently viewed needs view.
$routes->post('item/([0-9]+)/fav', '\\Apotheke\\Favourites\\Controllers\\FavouriteController::star/$1', ['permission' => 'edit']);
$routes->post('recent/clear', '\\Apotheke\\Favourites\\Controllers\\FavouriteController::clearRecent', ['permission' => 'view']);

// Catalog: the item page and form, saving, deleting and Undo (PLAN 11a). /save needs
// create, or edit with an id (the Permission filter applies legacy's rule).
$routes->match(['GET', 'HEAD'], 'new', $catalog . 'ItemController::create', ['permission' => 'create']);
$routes->match(['GET', 'HEAD'], 'item/([0-9]+)', $catalog . 'ItemController::show/$1', ['permission' => 'view']);
$routes->match(['GET', 'HEAD'], 'item/([0-9]+)/edit', $catalog . 'ItemController::edit/$1', ['permission' => 'edit']);
$routes->post('save', $catalog . 'ItemController::save', ['permission' => 'create']);
$routes->post('item/([0-9]+)/delete', $catalog . 'ItemController::delete/$1', ['permission' => 'delete']);
$routes->post('undo', $catalog . 'ItemController::undoAction', ['permission' => 'edit']);

// Catalog: an item's photos (PLAN 11a part 2); legacy gives every photo route edit.
$routes->post('item/([0-9]+)/photos', $catalog . 'PhotoController::add/$1', ['permission' => 'edit']);
$routes->post('item/([0-9]+)/photos/order', $catalog . 'PhotoController::order/$1', ['permission' => 'edit']);
$routes->post('item/([0-9]+)/photos/([0-9]+)/(cover|left|right|delete)', $catalog . 'PhotoController::act/$1/$2/$3', ['permission' => 'edit']);
$routes->match(['GET', 'HEAD'], 'item/([0-9]+)/photos/([0-9]+)/edit', $catalog . 'PhotoController::editForm/$1/$2', ['permission' => 'edit']);
$routes->post('item/([0-9]+)/photos/([0-9]+)/edit', $catalog . 'PhotoController::edit/$1/$2', ['permission' => 'edit']);

// Activity: recent activity and one item's full history.
$routes->match(['GET', 'HEAD'], 'activity', '\\Apotheke\\Activity\\Controllers\\ActivityController::index', ['permission' => 'view']);

// Auth: Control Panel → Users & sign-in (step 7 B): the page and its settings, sign-in
// on or off and your own password, accounts and roles. All need users (legacy route_perm()).
$security = '\\Apotheke\\Auth\\Controllers\\SecurityController';
$users = ['permission' => 'users'];
$routes->match(['GET', 'HEAD'], 'settings/security', $security . '::index', $users);
$routes->post('settings/security', $security . '::save', $users);
$routes->post('settings/security/reset', $security . '::reset', $users);
$routes->post('settings/password', $security . '::password', $users);
$routes->post('settings/users', $security . '::user', $users);
$routes->post('settings/users/([0-9]+)', $security . '::user/$1', $users);
$routes->post('settings/users/([0-9]+)/(role|password|rename|delete|approve)', $security . '::user/$1/$2', $users);
$routes->post('settings/roles', $security . '::role', $users);
$routes->post('settings/roles/([a-z0-9-]+)', $security . '::role/$1', $users);
$routes->post('settings/roles/([a-z0-9-]+)/delete', $security . '::role/$1/delete', $users);

// PLAN 11b: attachments (Catalog), and quantity, duplicate, merge and the list's bulk actions
// (BulkMerge). Permissions as legacy route_perm(); /bulk also checks the action's own.
$routes->post('item/([0-9]+)/attachments', $catalog . 'AttachmentController::add/$1', ['permission' => 'edit']);
$routes->post('item/([0-9]+)/attachments/([0-9]+)/delete', $catalog . 'AttachmentController::remove/$1/$2', ['permission' => 'edit']);
$routes->match(['GET', 'HEAD'], 'attachment/([0-9]+)', $catalog . 'AttachmentController::download/$1', ['permission' => 'view']);
$bulkMerge = '\\Apotheke\\BulkMerge\\Controllers\\BulkMergeController';
$routes->post('item/([0-9]+)/qty', $bulkMerge . '::qty/$1', ['permission' => 'edit']);
$routes->post('item/([0-9]+)/duplicate', $bulkMerge . '::duplicate/$1', ['permission' => 'create']);
$routes->match(['GET', 'HEAD'], 'item/([0-9]+)/merge', $bulkMerge . '::mergePage/$1', ['permission' => 'delete']);
$routes->post('item/([0-9]+)/merge', $bulkMerge . '::merge/$1', ['permission' => 'delete']);
$routes->post('bulk', $bulkMerge . '::bulk', ['permission' => 'bulk']);

// PLAN 13: QR labels (Labels), barcode lookup (Catalog) and lending (Lending). Permissions as
// legacy route_perm(). Every path under /barcode/ is the lookup (Core's LegacyPathRouter).
$routes->match(['GET', 'HEAD'], 'labels', '\\Apotheke\\Labels\\Controllers\\LabelsController::index', ['permission' => 'labels']);
$routes->match(['GET', 'HEAD'], 'item/([0-9]+)/label', '\\Apotheke\\Labels\\Controllers\\LabelsController::item/$1', ['permission' => 'labels']);
$routes->match(['GET', 'HEAD'], 'barcode', $catalog . 'BarcodeController::index', ['permission' => 'view']);
$routes->match(['GET', 'HEAD'], 'barcode/lookup', $catalog . 'BarcodeController::lookup', ['permission' => 'view']);
$routes->post('item/([0-9]+)/(lend|return)', '\\Apotheke\\Lending\\Controllers\\LendingController::act/$1/$2', ['permission' => 'lend']);
$routes->match(['GET', 'HEAD'], 'lent', '\\Apotheke\\Lending\\Controllers\\LendingController::index', ['permission' => 'view']);

// PLAN 13: stocktakes (Stocktake). Looking is "view", every change "stocktake" (legacy
// route_perm()); finishing with moves also checks "move". Every act answers every method,
// so a missing stocktake is "Stocktake not found." before the act is checked, as in legacy.
// A POST without a valid CSRF token is refused (419) before that, even for a missing
// stocktake, where legacy answers 404: the global CSRF filter runs first.
$stocktake = '\\Apotheke\\Stocktake\\Controllers\\StocktakeController';
$routes->match(['GET', 'HEAD'], 'stocktake', $stocktake . '::index', ['permission' => 'view']);
$routes->post('stocktake', $stocktake . '::index', ['permission' => 'stocktake']);
$routes->match(['GET', 'HEAD'], 'stocktake/([0-9]+)', $stocktake . '::take/$1', ['permission' => 'view']);
$routes->match(['GET', 'HEAD'], 'stocktake/([0-9]+)/(check|report|finish|cancel)', $stocktake . '::take/$1/$2', ['permission' => 'view']);
$routes->post('stocktake/([0-9]+)', $stocktake . '::take/$1', ['permission' => 'stocktake']);
$routes->post('stocktake/([0-9]+)/(check|report|finish|cancel)', $stocktake . '::take/$1/$2', ['permission' => 'stocktake']);

// PLAN 14: CSV import and export (ImportExport). Every /import route needs "import" and
// the export "export" (legacy route_perm()). A POST without a valid CSRF token is refused
// (419) before the import is looked up, where legacy answers 404 for a missing one.
// Legacy answers every method on these paths: /import shows its page for all but POST,
// an import redirects when done and is otherwise 404 but for GET (N-372). PUT, PATCH
// and DELETE need the CSRF token, as every non-safe method does (SEC-N10).
$import = '\\Apotheke\\ImportExport\\Controllers\\ImportController';
$importVerbs = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
$routes->match($importVerbs, 'import', $import . '::index', ['permission' => 'import']);
$routes->match($importVerbs, 'import/([0-9]+)', $import . '::import/$1', ['permission' => 'import']);
$routes->match($importVerbs, 'import/([0-9]+)/(preview|commit)', $import . '::import/$1/$2', ['permission' => 'import']);
$routes->match(['GET', 'HEAD'], 'export.csv', $import . '::export', ['permission' => 'export']);

// PLAN 14, part 2: backup and restore (Backup). Every route needs "backup" (legacy
// route_perm()), except the restore probe nginx asks before a large upload: legacy answers
// it before its permission check (204, 401 or 403) and never starts a session.
$backup = '\\Apotheke\\Backup\\Controllers\\BackupController';
$routes->match(['GET', 'HEAD'], 'backup', $backup . '::index', ['permission' => 'backup']);
$routes->match(['GET', 'HEAD'], 'backup.zip', $backup . '::download', ['permission' => 'backup']);
$routes->match(['GET', 'HEAD'], 'backup/restore/check', $backup . '::check', ['permission' => 'none']);
$routes->post('backup/restore', $backup . '::upload', ['permission' => 'backup']);
$routes->post('backup/restore/confirm', $backup . '::confirm', ['permission' => 'backup']);

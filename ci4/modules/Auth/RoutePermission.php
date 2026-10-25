<?php

declare(strict_types=1);

namespace Apotheke\Auth;

/**
 * Legacy route_perm(): the permission a request needs, from its method, path
 * and (for /save) its id. Every method but POST counts as a read, as in
 * legacy. A golden test keeps it equal to legacy for a shared corpus.
 */
final class RoutePermission
{
    /**
     * The path CI4's router matches, from IncomingRequest::getPath() (relative
     * to the base URL, still URL-encoded): decoded with urldecode() and trimmed
     * of slashes and spaces, as Router::handle() and checkRoutes() do, with
     * one leading slash, as legacy paths have.
     */
    public function routed(string $requestPath): string
    {
        $path = urldecode($requestPath === '' ? '/' : $requestPath);

        return $path === '/' ? '/' : '/' . trim($path, '/ ');
    }

    /**
     * The path as legacy compares it (rawurldecode() of the request path, not
     * trimmed), relative to the base URL, with one leading slash.
     */
    public function exact(string $requestPath): string
    {
        $path = rawurldecode($requestPath);

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    /** @param array<array-key,mixed> $post */
    public function need(string $method, string $path, array $post = []): string
    {
        $get = $method !== 'POST';
        if (in_array($path, ['/login', '/register'], true)) {
            return Permissions::PUBLIC;
        }
        if ($path === '/logout' || str_starts_with($path, '/account')) {
            return Permissions::SELF;
        }
        if (preg_match('#^/settings/(security|users|roles|password)(/|$)#', $path)) {
            return 'users';
        }
        if (preg_match('#^/settings(/|\.|$)#', $path)) {
            return 'settings';
        }
        if (preg_match('#^/backup(/|\.zip$|$)#', $path)) {
            return 'backup';
        }
        if (str_starts_with($path, '/import')) {
            return 'import';
        }
        if ($path === '/export.csv') {
            return 'export';
        }
        if (str_starts_with($path, '/stocktake')) {
            return $get ? 'view' : 'stocktake';
        }
        if ($path === '/labels' || preg_match('#^/item/\d+/label$#D', $path)) {
            return 'labels';
        }
        if ($path === '/new' || $path === '/autofill/suggest' || preg_match('#^/item/\d+/duplicate$#D', $path)) {
            return 'create';
        }
        if (preg_match('#^/item/\d+/(lend|return)$#D', $path) || ($path === '/lent' && !$get)) {
            return 'lend';
        }
        if ($path === '/bulk') {
            return 'bulk';
        }
        if (preg_match('#^/item/\d+/delete$#D', $path)) {
            return 'delete';
        }
        if ($path === '/save' && !$get) {
            $id = $post['id'] ?? 0;

            $n = is_array($id) ? ($id === [] ? 0 : 1) : (is_scalar($id) ? (int)$id : 0);   // PHP's (int) cast

            return $n > 0 ? 'edit' : 'create';
        }
        if (preg_match('#^/item/\d+/merge$#D', $path)) {
            return 'delete';
        }
        if (preg_match('#^/item/\d+/(edit|photos/\d+/edit)$#D', $path)) {
            return 'edit';
        }
        if ($get) {
            return 'view';
        }
        if (in_array($path, ['/theme', '/recent/clear'], true)) {
            return 'view';
        }

        return 'edit';
    }

    /**
     * Requests legacy answers before its permission check, so they need
     * nothing here either: the health checks, the branding files (GET),
     * the restore probe (GET, it answers 401/403 itself) and sign-out (POST).
     * $method is after HEAD → GET.
     */
    public function isBeforeCheck(string $method, string $path): bool
    {
        if ($path === '/healthz' || $path === '/health') {
            return true;
        }
        if ($method === 'GET') {
            return in_array($path, ['/theme.css', '/labels.css', '/manifest.webmanifest', '/favicon.ico', '/backup/restore/check'], true)
                || preg_match('#^/brand/[a-z0-9.\-]+$#D', $path) === 1;
        }

        return $method === 'POST' && $path === '/logout';
    }
}

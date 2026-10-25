<?php

declare(strict_types=1);

namespace Apotheke\Taxonomy\Controllers;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\WantsJson;
use Apotheke\Layout\Page;
use Apotheke\Taxonomy\Kind;
use Apotheke\Taxonomy\TaxonomyRepository;
use Apotheke\Taxonomy\Views\ManageView;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * /locations and /categories (legacy public/index.php, "locations &
 * categories management"): the list, add, rename, move, default and delete,
 * and the delete confirmation. The route's permission option and the global
 * CSRF filter run first. A missing entry is "<One> not found." (404); a path
 * legacy does not serve is "Page not found." (404).
 */
final class ManageController extends Controller
{
    /** GET /{locations|categories} */
    public function index(string $segment): ResponseInterface
    {
        $kind = $this->kind($segment);
        $t = Kind::get($kind);
        $done = $this->request->getGet('done');
        $toast = match (is_string($done) ? $done : '') {
            'added' => $t['One'] . ' added', 'exists' => 'That ' . $t['one'] . ' already exists', 'renamed' => $t['One'] . ' renamed',
            'default' => 'Default ' . $t['one'] . ' set', 'nodefault' => 'Default cleared', 'deleted' => $t['One'] . ' deleted',
            'moved' => $t['One'] . ' moved',
            default => null,
        };

        return $this->page(ManageView::manage($this->layout(), $kind, $this->repo()->all($kind), $this->repo()->noneCount($kind), null, $toast));
    }

    /** POST /{locations|categories}: add (an existing name is found, not added twice). */
    public function create(string $segment): ResponseInterface
    {
        $kind = $this->kind($segment);
        $t = Kind::get($kind);
        $repo = $this->repo();
        try {
            $raw = $this->post('parent');
            $parent = $kind === 'location' && ctype_digit($raw) ? (int)$raw : null;
            if ($parent !== null && $repo->get('location', $parent) === null) {
                throw new UserError('That parent location no longer exists.');
            }
            $r = $repo->createUnder($kind, $this->post('name'), $parent);
        } catch (UserError $e) {
            return $this->failed($kind, $e);
        }
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'id' => $r['id'], 'name' => $r['name'], 'created' => $r['created']]);
        }

        return $this->redirectTo($t['path'] . '?done=' . ($r['created'] ? 'added' : 'exists') . '#' . $t['param'] . '-' . $r['id']);
    }

    /** GET /{kind}/{id}/delete: what happens to the items. */
    public function confirmDelete(string $segment, string $id): ResponseInterface
    {
        $kind = $this->kind($segment);
        $eid = (int)$id;
        $entry = $this->entry($kind, $eid);
        $all = $this->repo()->all($kind);
        $others = array_values(array_filter($all, static fn (array $l): bool => $l['id'] !== $eid));
        if ($kind === 'location') {
            $entry['children'] = count(array_filter($all, static fn (array $l): bool => $l['parent_id'] === $eid));
            $me = array_values(array_filter($all, static fn (array $l): bool => $l['id'] === $eid))[0] ?? null;
            $entry['parent_name'] = $me !== null && $me['parent_id'] !== null ? $this->repo()->locationPath($me['parent_id']) : '';
        }

        return $this->page(ManageView::confirmDelete($this->layout(), $kind, $entry, $others));
    }

    /** POST /{kind}/{id}/{rename|default|delete|parent}. */
    public function act(string $segment, string $id, string $act): ResponseInterface
    {
        $kind = $this->kind($segment);
        $t = Kind::get($kind);
        $eid = (int)$id;
        $entry = $this->entry($kind, $eid);
        $repo = $this->repo();
        $anchor = '#' . $t['param'] . '-' . $eid;
        if ($act === 'rename') {
            try {
                $repo->rename($kind, $eid, $this->post('name'));
            } catch (UserError $e) {
                return $this->failed($kind, $e);
            }

            return $this->redirectTo($t['path'] . '?done=renamed' . $anchor);
        }
        if ($act === 'parent') {
            if ($kind !== 'location') {
                throw new UserError('Page not found.', 404);
            }
            $raw = $this->post('parent');
            if ($raw !== '' && !ctype_digit($raw)) {
                throw new UserError('Pick a location.', 422);
            }
            try {
                $repo->setParent($eid, $raw === '' ? null : (int)$raw);
            } catch (UserError $e) {
                return $this->failed($kind, $e);
            }
            if ($this->wantsJson()) {
                return $this->json(['ok' => true, 'path' => $repo->locationPath($eid)]);
            }

            return $this->redirectTo($t['path'] . '?done=moved' . $anchor);
        }
        if ($act === 'default') {
            $on = ($this->request->getPost('on') ?? '1') === '1';
            $repo->setDefault($kind, $eid, $on);

            return $this->redirectTo($t['path'] . '?done=' . ($on ? 'default' : 'nodefault') . $anchor);
        }
        // delete
        $action = $this->post('action');
        $target = null;
        if ($action === 'move') {
            $target = self::castInt($this->request->getPost('target'));
            if ($target === $eid || $repo->get($kind, $target) === null) {
                throw new UserError('Pick another ' . $t['one'] . ' to move the items to.', 422);
            }
        } elseif ($action !== 'unassign' && $entry['n'] > 0) {
            // No explicit choice: never drop assignments silently – ask first.
            return $this->redirectTo($t['path'] . '/' . $eid . '/delete');
        }
        $repo->delete($kind, $eid, $target);

        return $this->redirectTo($t['path'] . '?done=deleted');
    }

    /** Any other method or path under an entry: the entry must exist, then "Page not found.". */
    public function notFound(string $segment, string $id): ResponseInterface
    {
        $this->entry($this->kind($segment), (int)$id);
        throw new UserError('Page not found.', 404);
    }

    private function kind(string $segment): string
    {
        return Kind::fromSegment($segment) ?? throw new UserError('Page not found.', 404);
    }

    /** @return array{id:int,name:string,is_default:int,n:int} */
    private function entry(string $kind, int $id): array
    {
        return $this->repo()->get($kind, $id) ?? throw new UserError(Kind::get($kind)['One'] . ' not found.', 404);
    }

    /** A change the user can fix: 422 with the list page and the message, or JSON for fetch. */
    private function failed(string $kind, UserError $e): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->page(ManageView::manage($this->layout(), $kind, $this->repo()->all($kind), $this->repo()->noneCount($kind), $e->getMessage(), null), 422);
    }

    /** A posted field as legacy's (string)$_POST[...] (an array reads as "Array"). */
    private function post(string $field): string
    {
        $v = $this->request->getPost($field);

        return is_array($v) ? 'Array' : (is_scalar($v) ? (string)$v : '');
    }

    /** Legacy (int)$_POST[...]: an array is 0 when empty, else 1. */
    private static function castInt(mixed $v): int
    {
        return is_array($v) ? ($v === [] ? 0 : 1) : (is_scalar($v) ? (int)$v : 0);
    }

    private function wantsJson(): bool
    {
        $server = $this->request->getServer();

        return (new WantsJson())->check(is_array($server) ? $server : []);
    }

    /** Legacy redirect(): 303, or {ok, redirect} for fetch. */
    private function redirectTo(string $to): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'redirect' => $to]);
        }

        return $this->response->setStatusCode(303)->setHeader('Location', $to);
    }

    private function page(string $html, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
            ->setBody($html);
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
            ->setBody((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function repo(): TaxonomyRepository
    {
        /** @var TaxonomyRepository $repo */
        $repo = service('taxonomy');

        return $repo;
    }

    private function layout(): Page
    {
        /** @var Page $page */
        $page = service('layout');

        return $page;
    }
}

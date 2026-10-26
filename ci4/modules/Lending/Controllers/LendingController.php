<?php

declare(strict_types=1);

namespace Apotheke\Lending\Controllers;

use Apotheke\Catalog\Controllers\CatalogController;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Lending\Loans;
use Apotheke\Lending\Views\LentView;
use CodeIgniter\HTTP\ResponseInterface;

/** Legacy public/index.php: POST /item/{id}/lend and /item/{id}/return, GET /lent. */
final class LendingController extends CatalogController
{
    /** POST /item/{id}/(lend|return): back to the item's lending box, or to /lent. */
    public function act(string $id, string $act): ResponseInterface
    {
        $iid = (int)$id;
        /** @var ItemRepository $repo */
        $repo = service('itemRepository');
        if ($repo->get($iid) === null) {
            throw new UserError('Item not found.', 404);
        }
        $loans = $this->loans();
        if ($act === 'lend') {
            try {
                $loans->lend($iid, self::str($this->request->getPost('lent_to') ?? ''), Loans::date(self::str($this->request->getPost('lent_at') ?? ''), 'Lent date'),
                    Loans::date(self::str($this->request->getPost('lent_due') ?? ''), 'Due date'));
            } catch (UserError $e) {
                throw new UserError($e->getMessage(), 422);
            }
        } else {
            $loans->giveBack($iid);
        }

        return $this->redirectTo(self::str($this->request->getPost('back') ?? '') === '/lent' ? '/lent' : '/item/' . $iid . '#lending');
    }

    /** GET /lent */
    public function index(): ResponseInterface
    {
        /** @var LentView $view */
        $view = service('lentView');

        return $this->html($view->lent($this->loans()->out()));
    }

    private function loans(): Loans
    {
        /** @var Loans $l */
        $l = service('loans');

        return $l;
    }
}

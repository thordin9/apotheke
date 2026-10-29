<?php

declare(strict_types=1);

namespace Tests\ControlPanel;

use Apotheke\ControlPanel\Views\AutofillView;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/** The access token card says whether a token is set; it has no way to print one. */
final class AutofillViewTest extends CIUnitTestCase
{
    public function testTheTokenCardShowsOnlyTheState(): void
    {
        $view = (new ReflectionClass(AutofillView::class))->newInstanceWithoutConstructor();
        $set = $view->tokenHtml('CSRF', null, true, null);
        $this->assertStringContainsString('A token is <b>set</b>.', $set);
        $this->assertStringContainsString('id="af-token-clear"', $set);
        $this->assertMatchesRegularExpression('/<input type="password" name="token" id="af-token-input"[^>]*autocomplete="new-password"/', $set);
        $this->assertStringNotContainsString('value="', str_replace('value="CSRF"', '', str_replace('value="set"', '', str_replace('value="clear"', '', $set))));
        $unset = $view->tokenHtml('CSRF', 'Token: 1–512 printable characters, no spaces.', false, 'Set APP_KEY to encrypt stored secrets.');
        $this->assertStringContainsString('No token set.', $unset);
        $this->assertStringNotContainsString('af-token-clear', $unset);
        $this->assertStringContainsString('id="af-token-err" role="alert">Token: 1–512 printable characters, no spaces.', $unset);
        $this->assertStringContainsString('id="secret-key-warn">Set APP_KEY to encrypt stored secrets.', $unset);
    }

    public function testTheTokenIsNotAParameter(): void
    {
        $params = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new \ReflectionMethod(AutofillView::class, 'tokenHtml'))->getParameters());
        $this->assertSame(['tok', 'error', 'set', 'secretWarning'], $params, 'the card gets whether a token is set, never the token');
    }
}

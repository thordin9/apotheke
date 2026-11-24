<?php

declare(strict_types=1);

/**
 * The claim-page fixture (plan §5 S1.2b): what v3's `AuthViews::claim()` prints, captured once and
 * committed.
 *
 *     v4/scripts/gen-claim-fixture.sh            # regenerate test/fixtures/php-claim.json
 *     v4/scripts/gen-claim-fixture.sh --check    # hash check only (no PHP needed; what CI runs)
 *
 * Why this is not part of `php-html.json`: that fixture boots the whole v3 app through `php -S`, which
 * needs PHP with `intl` *and* `pdo_sqlite`. This page needs neither — it is one pure function of
 * `AuthViews::claim()` — so it is rendered here with a `Page` stub, the way `gen-session-fixture.php`
 * calls the session classes directly. The shell is not part of the captured body: `page.ts` is pinned
 * by the login golden test, and this fixture is about the form under it.
 *
 * `Page::e()` is v3's own escaping (`htmlspecialchars(…, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`), so
 * a name with quotes and angle brackets exercises the port's `e()` as well as its whitespace.
 */

namespace Apotheke\Layout {

    /** The four `Page` methods `AuthViews::claim()` calls, with markers where the shell would print. */
    final class Page
    {
        public function start(string $title, string $bodyClass = ''): string
        {
            return '';
        }

        public function brandLogo(int $size): string
        {
            return "<IMG-{$size}>";
        }

        public function csrf(): string
        {
            return 'TOKEN';
        }

        public function end(): string
        {
            return '';
        }

        public static function e(?string $s): string
        {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
}

namespace {

    use Apotheke\Auth\Views\AuthViews;
    use Apotheke\Layout\Page;

    $root = dirname(__DIR__, 2);
    require $root . '/modules/Auth/Views/AuthViews.php';

    /** The `<main>` fragment: the shell around it is `page.ts`'s job, pinned by the login fixture. */
    $body = static function (string $html): string {
        $start = strpos($html, '<main');
        $end = strpos($html, '</main>');

        return substr($html, $start, $end + 7 - $start);
    };

    $page = new Page();
    $v = ['appName' => 'Apotheke', 'usernameMax' => 40, 'passwordMin' => 8];
    $sources = ['modules/Auth/Views/AuthViews.php', 'modules/Auth/Accounts.php'];

    $fixture = [
        'generatedBy' => 'v4/scripts/gen-claim-fixture.sh',
        'php' => PHP_VERSION,
        'note' => 'Raw output of AuthViews::claim() with a Page stub: the form v3 prints, both states. Nothing normalised; the shell is not captured.',
        'sources' => [],
        'pages' => [
            'claim' => [
                'csrf' => 'TOKEN',
                'body' => $body(AuthViews::claim($page, $v, null, '')),
                'errorBody' => $body(AuthViews::claim(
                    $page,
                    $v,
                    'This claim link is not valid. Ask for a new one.',
                    'own<er> "q" \'z\'',
                )),
            ],
        ],
    ];

    foreach ($sources as $file) {
        $fixture['sources'][$file] = hash_file('sha256', $root . '/' . $file);
    }

    echo json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
}

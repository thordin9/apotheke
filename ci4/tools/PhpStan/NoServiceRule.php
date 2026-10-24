<?php

declare(strict_types=1);

namespace Apotheke\Tools\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * service() and Services:: belong in controllers, filters and Config/Services.php only.
 * CI4 builds filters with `new $class()`, so like controllers they have no constructor
 * injection and take collaborators from the shared (per-request) services.
 */
final class NoServiceRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof FuncCall && $node->name instanceof Name && $node->name->toString() === 'service') {
            return $this->violation($scope, 'service()');
        }
        if (!$node instanceof StaticCall || !$node->class instanceof Name || !$node->name instanceof Identifier) {
            return [];
        }
        $class = $node->class->toString();
        if ($class !== 'Services' && !str_ends_with($class, '\\Services')) {
            return [];
        }

        return $this->violation($scope, 'Services::');
    }

    /** @return list<\PHPStan\Rules\IdentifierRuleError> */
    private function violation(Scope $scope, string $what): array
    {
        $file = str_replace('\\', '/', $scope->getFile());
        if (str_contains($file, '/Controllers/') || str_contains($file, '/Filters/') || str_ends_with($file, '/app/Config/Services.php')) {
            return [];
        }

        return [
            RuleErrorBuilder::message($what . ' is only allowed in controllers, filters and Config/Services.php')
                ->identifier('apotheke.noService')
                ->build(),
        ];
    }
}

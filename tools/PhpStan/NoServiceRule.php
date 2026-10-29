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
 *
 * Filters are matched exactly: a file directly in modules/<Module>/Filters/, and
 * only inside its before() or after() method, so a filter never holds a service in a
 * static, a property or its constructor.
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
        if (str_contains($file, '/Controllers/') || str_ends_with($file, '/app/Config/Services.php') || $this->inFilterHook($file, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message($what . ' is only allowed in controllers, filters and Config/Services.php')
                ->identifier('apotheke.noService')
                ->build(),
        ];
    }

    /** A call inside before() or after() of a class file directly in modules/<Module>/Filters/. */
    private function inFilterHook(string $file, Scope $scope): bool
    {
        if (preg_match('#/modules/[A-Za-z0-9]+/Filters/[A-Za-z0-9]+\.php$#D', $file) !== 1) {
            return false;
        }
        if (!$scope->isInClass() || $scope->isInAnonymousFunction()) {
            return false;
        }
        $function = $scope->getFunction();

        return $function !== null && in_array($function->getName(), ['before', 'after'], true);
    }
}

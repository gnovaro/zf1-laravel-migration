<?php

namespace App\Services\Zf1\Parser;

use App\Services\Zf1\Contracts\ParserInterface;
use Illuminate\Support\Facades\File;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

class ControllerParser implements ParserInterface
{
    private readonly \PhpParser\Parser $parser;

    private readonly NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder;
    }

    public function parse(string $filePath): ?array
    {
        if (!File::exists($filePath)) {
            return null;
        }

        $code = File::get($filePath);

        try {
            $ast = $this->parser->parse($code);
        } catch (Error) {
            return null;
        }

        if ($ast === null) {
            return null;
        }

        $classNode = $this->nodeFinder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);

        if ($classNode === null) {
            return null;
        }

        $className = $classNode->name->name ?? 'UnknownController';
        $extends = $classNode->extends ? $classNode->extends->toString() : null;

        $methods = [];
        $viewAssignments = [];
        $usedHelpers = [];
        $usedMethods = [];

        foreach ($classNode->getMethods() as $method) {
            $methodName = $method->name->name;

            if (str_ends_with($methodName, 'Action')) {
                $actionName = substr($methodName, 0, -6);
                $actionInfo = $this->analyzeActionMethod($method);
                $methods[$actionName] = $actionInfo;

                foreach ($actionInfo['view_assignments'] as $var) {
                    $viewAssignments[] = $var;
                }
                foreach ($actionInfo['helpers'] as $helper) {
                    $usedHelpers[] = $helper;
                }
                foreach ($actionInfo['method_calls'] as $mc) {
                    $usedMethods[] = $mc;
                }
            } elseif (in_array($methodName, ['init', 'preDispatch', 'postDispatch'])) {
                $methods[$methodName] = $this->analyzeActionMethod($method);
            }
        }

        $magicCalls = $classNode !== null ? $this->findMagicMethodCalls($classNode) : [];

        return [
            'class' => $className,
            'namespace' => $classNode !== null ? $this->getNamespace($classNode) : null,
            'extends' => $extends,
            'methods' => $methods,
            'view_assignments' => $this->deduplicateViewAssignments($viewAssignments),
            'used_helpers' => array_unique($usedHelpers),
            'used_methods' => array_unique($usedMethods),
            'magic_calls' => $magicCalls,
            'file_path' => $filePath,
            'uses' => $this->getUses($ast),
        ];
    }

    private function analyzeActionMethod(Node\Stmt\ClassMethod $method): array
    {
        $viewAssignments = [];
        $helpers = [];
        $methodCalls = [];
        $params = [];
        $redirects = [];
        $forwards = [];
        $body = '';

        if ($method->getStmts() === null) {
            return [
                'view_assignments' => [],
                'helpers' => [],
                'method_calls' => [],
                'params' => [],
                'redirects' => [],
                'forwards' => [],
                'body' => '',
            ];
        }

        foreach ($method->getStmts() as $stmt) {
            $this->analyzeNode($stmt, $viewAssignments, $helpers, $methodCalls, $params, $redirects, $forwards);
        }

        return [
            'view_assignments' => $viewAssignments,
            'helpers' => $helpers,
            'method_calls' => $methodCalls,
            'params' => $params,
            'redirects' => $redirects,
            'forwards' => $forwards,
        ];
    }

    private function analyzeNode(
        Node $node,
        array &$viewAssignments,
        array &$helpers,
        array &$methodCalls,
        array &$params,
        array &$redirects,
        array &$forwards,
    ): void {
        if ($node instanceof Node\Expr\MethodCall) {
            $methodName = $node->name->name ?? $node->name ?? null;

            if ($methodName === null) {
                return;
            }

            if ($node->var instanceof Node\Expr\PropertyFetch) {
                $propName = $node->var->name->name ?? null;

                if ($propName === 'view' && $node->var->var instanceof Node\Expr\Variable && $node->var->var->name === 'this') {
                    if ($methodName === '__set' || $methodName === 'assign') {
                        if (isset($node->args[0])) {
                            $arg = $node->args[0]->value;
                            if ($arg instanceof Node\Scalar\String_) {
                                $viewAssignments[] = $arg->value;
                            }
                        }
                    } else {
                        $methodCalls[] = '$this->view->' . $methodName . '()';
                    }
                } elseif ($propName === '_helper') {
                    $helperName = $methodName;
                    $helpers[] = $helperName;
                }
            }

            if ($node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                if (str_starts_with($methodName, '_getParam')) {
                    if (isset($node->args[0])) {
                        $arg = $node->args[0]->value;
                        $paramName = $arg instanceof Node\Scalar\String_ ? $arg->value : '?';
                        $params[] = $paramName;
                    }
                } elseif ($methodName === '_redirect') {
                    if (isset($node->args[0])) {
                        $arg = $node->args[0]->value;
                        $redirects[] = $arg instanceof Node\Scalar\String_ ? $arg->value : '?';
                    }
                } elseif ($methodName === '_forward') {
                    if (isset($node->args[0])) {
                        $arg = $node->args[0]->value;
                        $forwards[] = $arg instanceof Node\Scalar\String_ ? $arg->value : '?';
                    }
                } elseif (!str_starts_with($methodName, '_')) {
                    $call = '$this->' . $methodName . '()';
                    if (!in_array($call, $methodCalls, true)) {
                        $methodCalls[] = $call;
                    }
                }
            }

            return;
        }

        if ($node instanceof Node\Expr\Assign
            && $node->var instanceof Node\Expr\PropertyFetch
            && $node->var->var instanceof Node\Expr\PropertyFetch
            && $node->var->var->name->name === 'view'
            && $node->var->var->var instanceof Node\Expr\Variable
            && $node->var->var->var->name === 'this') {
            $assignVar = $node->var->name->name ?? '?';
            $value = $this->resolveExpr($node->expr);
            $viewAssignments[] = [
                'var' => $assignVar,
                'value' => $value,
            ];

            return;
        }

        foreach ($node->getSubNodeNames() as $subNode) {
            $child = $node->$subNode;
            if ($child instanceof Node) {
                $this->analyzeNode($child, $viewAssignments, $helpers, $methodCalls, $params, $redirects, $forwards);
            } elseif (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node) {
                        $this->analyzeNode($item, $viewAssignments, $helpers, $methodCalls, $params, $redirects, $forwards);
                    }
                }
            }
        }
    }

    private function deduplicateViewAssignments(array $assignments): array
    {
        $seen = [];
        $result = [];

        foreach ($assignments as $a) {
            $key = is_string($a) ? $a : ($a['var'] ?? '?');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $a;
            }
        }

        return $result;
    }

    private function resolveExpr(Node\Expr $expr): string
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_ => "'{$expr->value}'",
            $expr instanceof Node\Scalar\LNumber => (string) $expr->value,
            $expr instanceof Node\Expr\Variable => '$' . $expr->name,
            $expr instanceof Node\Expr\Array_ => '[]',
            $expr instanceof Node\Expr\ConstFetch => $expr->name->toString(),
            $expr instanceof Node\Expr\UnaryMinus => '-' . $this->resolveExpr($expr->expr),
            $expr instanceof Node\Expr\BinaryOp\Concat => $this->resolveExpr($expr->left) . ' . ' . $this->resolveExpr($expr->right),
            $expr instanceof Node\Expr\MethodCall => '$this->' . ($expr->name->name ?? '?') . '()',
            $expr instanceof Node\Expr\FuncCall => ($expr->name instanceof Node\Name ? $expr->name->toString() : '?') . '()',
            default => '/* expression */',
        };
    }

    private function findMagicMethodCalls(Node\Stmt\Class_ $classNode): array
    {
        $methodsUsingMagic = [];

        foreach ($classNode->getMethods() as $method) {
            if ($method->getStmts() === null) {
                continue;
            }

            $stmts = $method->getStmts();
            foreach ($stmts as $stmt) {
                $this->findMagicHelperCalls($stmt, $methodsUsingMagic);
            }
        }

        return $methodsUsingMagic;
    }

    private function findMagicHelperCalls(Node $node, array &$found): void
    {
        if ($node instanceof Node\Expr\MethodCall
            && $node->var instanceof Node\Expr\PropertyFetch
            && $node->var->name->name === '_helper'
            && $node->var->var instanceof Node\Expr\Variable
            && $node->var->var->name === 'this') {
            $found[] = $node->name->name ?? '?';
        }

        foreach ($node->getSubNodeNames() as $subNode) {
            $child = $node->$subNode;
            if ($child instanceof Node) {
                $this->findMagicHelperCalls($child, $found);
            } elseif (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node) {
                        $this->findMagicHelperCalls($item, $found);
                    }
                }
            }
        }
    }

    private function getNamespace(Node\Stmt\Class_ $classNode): ?string
    {
        $parent = $classNode->getAttribute('parent');
        while ($parent !== null) {
            if ($parent instanceof Node\Stmt\Namespace_) {
                return $parent->name->toString();
            }
            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    private function getUses(array $ast): array
    {
        $uses = [];
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Use_) {
                foreach ($node->uses as $use) {
                    $uses[] = $use->name->toString();
                }
            }
        }

        return $uses;
    }
}

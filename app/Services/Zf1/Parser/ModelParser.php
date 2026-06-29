<?php

namespace App\Services\Zf1\Parser;

use App\Services\Zf1\Contracts\ParserInterface;
use Illuminate\Support\Facades\File;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

class ModelParser implements ParserInterface
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

        $className = $classNode->name->name ?? 'UnknownModel';
        $namespace = $this->getNamespace($classNode);

        $tableName = null;
        $primaryKey = 'id';
        $dependentTables = [];
        $referenceMap = [];
        $rowClass = null;

        foreach ($classNode->getProperties() as $prop) {
            $propName = $prop->props[0]->name->name ?? null;

            if ($propName === '_name' && $prop->props[0]->default instanceof Node\Scalar\String_) {
                $tableName = $prop->props[0]->default->value;
            }

            if ($propName === '_primary' && $prop->props[0]->default !== null) {
                $primaryKey = $this->resolveDefaultValue($prop->props[0]->default);
            }

            if ($propName === '_rowClass' && $prop->props[0]->default instanceof Node\Scalar\String_) {
                $rowClass = $prop->props[0]->default->value;
            }

            if ($propName === '_dependentTables' && $prop->props[0]->default instanceof Node\Expr\Array_) {
                foreach ($prop->props[0]->default->items as $item) {
                    if ($item !== null && $item->value instanceof Node\Scalar\String_) {
                        $dependentTables[] = $item->value->value;
                    }
                }
            }

            if ($propName === '_referenceMap' && $prop->props[0]->default instanceof Node\Expr\Array_) {
                $referenceMap = $this->parseReferenceMap($prop->props[0]->default);
            }
        }

        $isDbTable = $this->isDbTableClass($classNode);

        $methods = [];
        foreach ($classNode->getMethods() as $method) {
            $methods[] = $method->name->name;
        }

        return [
            'class' => $className,
            'namespace' => $namespace,
            'table_name' => $tableName,
            'primary_key' => $primaryKey,
            'dependent_tables' => $dependentTables,
            'reference_map' => $referenceMap,
            'row_class' => $rowClass,
            'is_db_table' => $isDbTable,
            'methods' => $methods,
            'file_path' => $filePath,
        ];
    }

    private function isDbTableClass(Node\Stmt\Class_ $classNode): bool
    {
        if ($classNode->extends === null) {
            return false;
        }

        $extendsName = $classNode->extends->toString();

        return str_contains($extendsName, 'Zend_Db_Table')
            || str_contains($extendsName, 'DbTable')
            || $extendsName === 'Zend_Db_Table_Abstract';
    }

    private function parseReferenceMap(Node\Expr\Array_ $array): array
    {
        $map = [];

        foreach ($array->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : '?';

            if ($item->value instanceof Node\Expr\Array_) {
                $columns = [];
                foreach ($item->value->items as $subItem) {
                    if ($subItem === null || $subItem->key === null) {
                        continue;
                    }
                    $subKey = $subItem->key instanceof Node\Scalar\String_ ? $subItem->key->value : '?';
                    $subVal = $this->resolveDefaultValue($subItem->value);
                    $columns[$subKey] = $subVal;
                }
                $map[$key] = $columns;
            }
        }

        return $map;
    }

    private function resolveDefaultValue(Node\Expr $node): mixed
    {
        return match (true) {
            $node instanceof Node\Scalar\String_ => $node->value,
            $node instanceof Node\Scalar\LNumber => $node->value,
            $node instanceof Node\Expr\ClassConstFetch => $node->name->name ?? '?',
            $node instanceof Node\Expr\Array_ => [],
            default => null,
        };
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
}

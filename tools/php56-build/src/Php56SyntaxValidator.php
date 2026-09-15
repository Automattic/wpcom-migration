<?php

declare(strict_types=1);

namespace WordPress\Reprint\Build;

use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\AssignOp\Coalesce as AssignCoalesce;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Spaceship;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * Rejects the constructs the downgrade rules are meant to remove.
 *
 * This is not a full parser-level check of the target PHP version: it walks
 * the AST for the specific node shapes the Rector rules downgrade, not every
 * construct that version's parser would reject. CI's `php -l` on the target
 * PHP version is the real gate.
 */
final class Php56SyntaxValidator
{
    private Parser $parser;
    private string $target_version;

    public function __construct(string $target_version = '5.6')
    {
        if (!in_array($target_version, ['5.6', '7.0'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported target version: %s.', $target_version));
        }
        $this->target_version = $target_version;
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param string[] $paths Files and directories containing generated PHP.
     */
    public function assertPaths(array $paths): void
    {
        foreach ($this->phpFiles($paths) as $file) {
            $this->assertFile($file);
        }
    }

    public function assertFile(string $file): void
    {
        $code = file_get_contents($file);
        if ($code === false) {
            throw new RuntimeException(sprintf('Could not read generated PHP file %s.', $file));
        }

        try {
            $statements = $this->parser->parse($code);
        } catch (\Throwable $throwable) {
            throw new RuntimeException(sprintf(
                'Generated PHP could not be parsed in %s: %s',
                $file,
                $throwable->getMessage()
            ), 0, $throwable);
        }

        if ($statements === null) {
            return;
        }

        $name_resolver = new NodeTraverser();
        $name_resolver->addVisitor(new NameResolver());
        $statements = $name_resolver->traverse($statements);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($file, $this->target_version) extends NodeVisitorAbstract {
            private string $file;
            private string $target_version;

            public function __construct(string $file, string $target_version)
            {
                $this->file = $file;
                $this->target_version = $target_version;
            }

            public function enterNode(Node $node): ?Node
            {
                $reason = $this->unsupportedReason($node);
                if ($reason !== null) {
                    throw new RuntimeException(sprintf(
                        'Generated PHP still contains %s in %s on line %d.',
                        $reason,
                        $this->file,
                        $node->getStartLine()
                    ));
                }

                return null;
            }

            private function unsupportedReason(Node $node): ?string
            {
                if ($node instanceof FunctionLike && $node->getReturnType() !== null) {
                    return 'a return type declaration';
                }
                if ($node instanceof Param && $node->flags !== 0) {
                    return 'a promoted parameter';
                }
                if (
                    $node instanceof Param
                    && $node->type !== null
                    && !$this->isSupportedParameterType($node->type)
                ) {
                    return $this->target_version === '7.0'
                        ? 'a parameter type which PHP 7.0 cannot parse'
                        : 'a parameter type which PHP 5.6 cannot parse';
                }
                if ($node instanceof Property && $node->type !== null) {
                    return 'a typed property';
                }
                if ($node instanceof ClassConst && $node->flags !== 0) {
                    return 'a class-constant modifier';
                }
                if ($this->target_version === '5.6' && $node instanceof Coalesce) {
                    return 'a null-coalescing operator';
                }
                if ($node instanceof AssignCoalesce) {
                    return $this->target_version === '7.0'
                        ? 'a null-coalescing assignment'
                        : 'a null-coalescing operator';
                }
                if ($this->target_version === '5.6' && $node instanceof Spaceship) {
                    return 'a spaceship operator';
                }
                if ($this->target_version === '5.6' && $node instanceof YieldFrom) {
                    return 'a yield-from expression';
                }
                if ($node instanceof ArrowFunction) {
                    return 'an arrow function';
                }
                if ($this->target_version === '5.6' && $node instanceof GroupUse) {
                    return 'a grouped use declaration';
                }
                if ($node instanceof Catch_ && count($node->types) !== 1) {
                    return 'a multi-catch declaration';
                }
                if ($this->target_version === '5.6' && $node instanceof New_ && $node->class instanceof Class_) {
                    return 'an anonymous class';
                }
                if ($node instanceof List_) {
                    if ($node->getAttribute('kind') === List_::KIND_ARRAY) {
                        return 'short array destructuring';
                    }
                    foreach ($node->items as $item) {
                        if ($item !== null && $item->key !== null) {
                            return 'keyed array destructuring';
                        }
                    }
                }
                if ($node instanceof Arg && $node->name !== null) {
                    return 'a named argument';
                }
                if (property_exists($node, 'attrGroups') && $node->attrGroups !== []) {
                    return 'an attribute';
                }

                return null;
            }

            private function isSupportedParameterType(Node $type): bool
            {
                if ($this->isPhp56ParameterType($type)) {
                    return true;
                }

                if ($this->target_version !== '7.0') {
                    return false;
                }

                if ($type instanceof Name) {
                    $resolved_name = $type->getAttribute('resolvedName');
                    $type_name = $resolved_name instanceof Name
                        ? $resolved_name->toString()
                        : $type->toString();

                    return strtolower($type_name) === 'throwable';
                }

                return $type instanceof Identifier
                    && in_array(strtolower($type->name), ['int', 'float', 'string', 'bool'], true);
            }

            private function isPhp56ParameterType(Node $type): bool
            {
                if ($type instanceof Name) {
                    $resolved_name = $type->getAttribute('resolvedName');
                    $type_name = $resolved_name instanceof Name
                        ? $resolved_name->toString()
                        : $type->toString();

                    return strtolower($type_name) !== 'throwable';
                }

                return $type instanceof Identifier
                    && in_array(strtolower($type->name), ['array', 'callable'], true);
            }
        });
        $traverser->traverse($statements);
    }

    /**
     * @param string[] $paths Files and directories to inspect.
     * @return string[]
     */
    private function phpFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                if (substr($path, -4) === '.php') {
                    $files[] = $path;
                }
                continue;
            }
            if (!is_dir($path)) {
                throw new RuntimeException(sprintf('Generated PHP path does not exist: %s.', $path));
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException(sprintf('Generated PHP path contains a symbolic link: %s.', $file->getPathname()));
                }
                if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return array_values(array_unique($files));
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Pest\Arch\Factories\ObjectDescriptionFactory;
use Pest\Arch\Repositories\ObjectsRepository;
use Pest\Arch\Support\PhpCoreExpressions;
use PhpParser\ErrorHandler;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PHPUnit\Architecture\Elements\ObjectDescription;
use PHPUnit\Architecture\Services\ServiceContainer;
use ReflectionProperty;

/**
 * P6-11: the Pest arch() tests parse every class of the app once per distinct namespace they ask about, and the same
 * file is reached through several overlapping namespaces ('App', 'Juwri\Modules', 'Juwri\Modules\Storefront', one class
 * per baseline entry, ...). Pest's repository caches per namespace, not per file, so each file's syntax tree was held
 * once per namespace asking for it (an OOM at 4G for the Architecture directory). This parser decorator parses a given
 * source text once and hands every description the same tree. The trees are read-only for the checks (name resolution
 * is idempotent), so nothing is skipped or loosened: the same files, nodes and violations, just one copy in memory.
 */
final class ArchParseCache implements Parser
{
    /** @var array<string, array<int, Stmt>|null> */
    private array $trees = [];

    public function __construct(private readonly Parser $inner) {}

    public static function install(): void
    {
        ServiceContainer::init();
        // Method bodies: once the names in them are resolved (NameResolver runs first), keep only the nodes the checks
        // read, see ArchMethodBodyTrimmer.
        ServiceContainer::$nodeTraverser->addVisitor(new ArchMethodBodyTrimmer);
        if (! (ServiceContainer::$parser instanceof self)) {
            ServiceContainer::$parser = new self(ServiceContainer::$parser);
        }
        // Stop Pest's factory from running ServiceContainer::init() again (it would replace the parser).
        $flag = new ReflectionProperty(ObjectDescriptionFactory::class, 'serviceContainerInitialized');
        $flag->setValue(null, true);
    }

    /**
     * After an arch test: every description Pest's repository holds keeps its whole syntax tree, only to locate a
     * violation later (Blueprint searches `stmts` for the offending name). Everything else (uses, methods) was read at
     * creation. Replace each tree by the flat list of nodes such a search can match (names, plus the function and
     * language-construct expressions the arch checks use), in source order, so failures still report the same line,
     * and let the parse cache go. This is what keeps the Architecture directory far below the old multi-GB peak.
     */
    public static function slimRepository(): void
    {
        $repository = new ReflectionProperty(ObjectsRepository::class, 'instance');
        $instance = $repository->getValue();
        if ($instance !== null) {
            $cache = new ReflectionProperty($instance, 'cachedObjectsPerPrefix');
            $seen = [];
            foreach ($cache->getValue($instance) as $variants) {
                foreach ($variants as $objects) {
                    foreach ($objects as $object) {
                        if (! $object instanceof ObjectDescription || isset($seen[spl_object_id($object)])) {
                            continue;
                        }
                        $seen[spl_object_id($object)] = true;
                        $object->stmts = self::flatten($object->stmts);
                    }
                }
            }
        }
        self::flush();
        gc_collect_cycles();
    }

    /**
     * @param  array<int, Node>  $stmts
     * @return array<int, Node>
     */
    private static function flatten(array $stmts): array
    {
        if ($stmts !== [] && ! reset($stmts) instanceof Stmt\Namespace_ && ! reset($stmts) instanceof Stmt) {
            return $stmts; // already flattened
        }
        $collector = new ArchNodeCollector;
        (new NodeTraverser($collector))->traverse($stmts);

        return $collector->nodes;
    }

    public static function flush(): void
    {
        if (isset(ServiceContainer::$parser) && ServiceContainer::$parser instanceof self) {
            ServiceContainer::$parser->trees = [];
        }
    }

    public function getTokens(): array
    {
        return $this->inner->getTokens();
    }

    public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
    {
        $key = md5($code).strlen($code);
        if (! array_key_exists($key, $this->trees)) {
            $this->trees[$key] = $this->inner->parse($code, $errorHandler);
        }

        return $this->trees[$key];
    }
}

/** Collects, in source order, the nodes an arch check or a violation report can match: names and core expressions. */
final class ArchNodeCollector extends NodeVisitorAbstract
{
    /** @var array<int, Node> */
    public array $nodes = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Name) {
            $this->nodes[] = $node;
        } else {
            foreach (PhpCoreExpressions::$ENABLED as $class) {
                if ($node instanceof $class) {
                    $this->nodes[] = $node;
                    break;
                }
            }
        }

        return null;
    }
}

/**
 * Runs after NameResolver: a resolved method body is replaced by the flat list of its names and core expressions.
 * The arch libraries read nothing else from a body (uses, banned functions, violation lines), and bodies are most of
 * the nodes in memory. The method's signature, its doc comment and its line span stay intact.
 */
final class ArchMethodBodyTrimmer extends NodeVisitorAbstract
{
    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassMethod && $node->stmts !== null && $node->stmts !== []) {
            $collector = new ArchNodeCollector;
            (new NodeTraverser($collector))->traverse($node->stmts);
            $node->stmts = $collector->nodes;
        }

        return null;
    }
}

#!/usr/bin/env php
<?php

/**
 * Deep static audit of app/ — routes, methods, scopes, views.
 *
 * Usage:
 *   php tools/deep-audit-app.php
 *   php tools/deep-audit-app.php --report=tools/deep-audit-report.txt
 *
 * Exit: 0 = pass, 1 = issues found
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$argv = $argv ?? [];
$reportPath = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--report=')) {
        $reportPath = substr($arg, 9);
    }
}

require $root . '/vendor/autoload.php';

/** Eloquent/query builder methods — not custom model APIs. */
$builderMethods = [
    'class', 'query', 'make', 'create', 'find', 'findOrFail', 'where', 'whereIn', 'whereKey', 'whereDate',
    'whereHas', 'whereDoesntHave', 'orWhere', 'all', 'destroy', 'with', 'select', 'orderBy', 'orderByDesc',
    'get', 'first', 'count', 'sum', 'pluck', 'when', 'tap', 'cursor', 'chunk', 'insert', 'updateOrCreate',
    'firstOrCreate', 'update', 'delete', 'exists', 'doesntExist', 'value', 'max', 'min', 'avg', 'paginate',
    'simplePaginate', 'limit', 'take', 'offset', 'skip', 'latest', 'oldest', 'newQuery', 'on', 'from', 'raw',
    'selectRaw', 'whereRaw', 'havingRaw', 'orderByRaw', 'join', 'leftJoin', 'rightJoin', 'groupBy', 'having',
    'distinct', 'lockForUpdate', 'sharedLock', 'withoutGlobalScopes', 'withoutGlobalScope', 'withTrashed',
    'onlyTrashed', 'restore', 'forceDelete', 'trashed', 'is', 'resolveConnection', 'onWriteConnection',
    'has', 'doesntHave', 'withCount', 'load', 'loadMissing', 'loadCount', 'attach', 'detach', 'sync',
    'increment', 'decrement', 'upsert', 'sole', 'findMany', 'replicate', 'refresh',
];

/** @var list<array{category: string, message: string, file?: string}> */
$issues = [];
/** @var array<string, true> */
$issueKeys = [];

function rel(string $path, string $root): string
{
    return ltrim(str_replace('\\', '/', str_replace(str_replace('\\', '/', $root), '', str_replace('\\', '/', $path))), '/');
}

function methodExists(string $class, string $method): bool
{
    if (! class_exists($class)) {
        return false;
    }
    if (method_exists($class, $method)) {
        return true;
    }

    return method_exists($class, 'scope' . ucfirst($method));
}

function addIssue(string $category, string $message, ?string $file = null): void
{
    global $issues, $issueKeys;
    $key = $category.'|'.$message.'|'.($file ?? '');
    if (isset($issueKeys[$key])) {
        return;
    }
    $issueKeys[$key] = true;
    $issues[] = array_filter(['category' => $category, 'message' => $message, 'file' => $file]);
}

function collectPhpFiles(string ...$dirs): array
{
    $files = [];
    foreach ($dirs as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

function collectBladeFiles(string $dir): array
{
    $files = [];
    if (! is_dir($dir)) {
        return $files;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/** @return array<string, string> shortName => FQCN */
function parseUseMap(string $content): array
{
    $map = [];
    if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $fqcn = $match[1];
            $short = $match[2] ?? (str_contains($fqcn, '\\') ? substr($fqcn, strrpos($fqcn, '\\') + 1) : $fqcn);
            $map[$short] = $fqcn;
        }
    }

    return $map;
}

function resolveClass(string $name, array $useMap): ?string
{
    if (str_starts_with($name, '\\')) {
        return ltrim($name, '\\');
    }
    if (isset($useMap[$name])) {
        return $useMap[$name];
    }
    if (str_starts_with($name, 'App\\')) {
        return $name;
    }

    return null;
}

// ── 1. Route → controller public method ─────────────────────────────────
echo "Checking routes → controller methods...\n";
$routeFiles = glob($root . '/routes/*.php') ?: [];
$routePattern = '/\[(?:\\\\App\\\\Http\\\\Controllers\\\\|App\\\\Http\\\\Controllers\\\\)?(\w+)::class,\s*[\'"](\w+)[\'"]\]/';

foreach ($routeFiles as $routeFile) {
    $content = file_get_contents($routeFile) ?: '';
    if (preg_match_all($routePattern, $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $controller = 'App\\Http\\Controllers\\' . $match[1];
            $method = $match[2];
            if (! class_exists($controller)) {
                addIssue('route', "Controller class missing: {$controller} (method {$method})", rel($routeFile, $root));
                continue;
            }
            $ref = new ReflectionClass($controller);
            if (! $ref->hasMethod($method)) {
                addIssue('route', "{$controller}::{$method}() referenced in routes but not defined", rel($routeFile, $root));
                continue;
            }
            $mref = $ref->getMethod($method);
            if (! $mref->isPublic()) {
                addIssue('route', "{$controller}::{$method}() exists but is not public", rel($routeFile, $root));
            }
        }
    }
    // Fully qualified controller refs
    if (preg_match_all('/\[\\\\App\\\\Http\\\\Controllers\\\\(\w+)::class,\s*[\'"](\w+)[\'"]\]/', $content, $m2, PREG_SET_ORDER)) {
        foreach ($m2 as $match) {
            $controller = 'App\\Http\\Controllers\\' . $match[1];
            $method = $match[2];
            if (! class_exists($controller)) {
                addIssue('route', "Controller class missing: {$controller} (method {$method})", rel($routeFile, $root));
                continue;
            }
            if (! method_exists($controller, $method)) {
                addIssue('route', "{$controller}::{$method}() referenced in routes but not defined", rel($routeFile, $root));
            }
        }
    }
}

// ── 2. Static calls App\Models|Support|Services ───────────────────────────
echo "Checking App static method calls...\n";
$scanPhpDirs = [$root . '/app', $root . '/routes'];
$staticPatterns = [
    '/\\\\App\\\\(Models|Support|Services(?:\\\\[A-Za-z0-9_]+)?)\\\\([A-Za-z0-9_]+)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
    '/\b([A-Za-z0-9_]+)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
];

foreach (collectPhpFiles(...$scanPhpDirs) as $file) {
    $content = file_get_contents($file) ?: '';
    $useMap = parseUseMap($content);
    $relFile = rel($file, $root);

    if (preg_match_all($staticPatterns[0], $content, $fqMatches, PREG_SET_ORDER)) {
        foreach ($fqMatches as $match) {
            $class = 'App\\' . $match[1] . '\\' . $match[2];
            $class = str_replace('\\\\', '\\', $class);
            // Fix Services sub-namespace
            if (str_starts_with($match[1], 'Services')) {
                $class = 'App\\Services\\' . $match[2];
                if (str_contains($match[0], 'Exports\\')) {
                    $class = 'App\\Services\\Exports\\' . $match[2];
                }
            } else {
                $class = 'App\\' . $match[1] . '\\' . $match[2];
            }
            $method = $match[3];
            if (in_array($method, $builderMethods, true)) {
                continue;
            }
            if (! class_exists($class)) {
                addIssue('static', "Class missing: {$class}::{$method}()", $relFile);
            } elseif (! methodExists($class, $method)) {
                addIssue('static', "{$class}::{$method}() called but not defined", $relFile);
            }
        }
    }

    // Imported short class names
    if (preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $content, $shortMatches, PREG_SET_ORDER)) {
        foreach ($shortMatches as $match) {
            $short = $match[1];
            $method = $match[2];
            $class = resolveClass($short, $useMap);
            if ($class === null || ! str_starts_with($class, 'App\\')) {
                continue;
            }
            if (! in_array($class, ['App\\Models', 'App\\Support', 'App\\Services'], true) && ! str_starts_with($class, 'App\\Models\\') && ! str_starts_with($class, 'App\\Support\\') && ! str_starts_with($class, 'App\\Services\\')) {
                continue;
            }
            if (in_array($method, $builderMethods, true)) {
                continue;
            }
            if (! class_exists($class)) {
                addIssue('static', "Class missing: {$class}::{$method}()", $relFile);
            } elseif (! methodExists($class, $method)) {
                addIssue('static', "{$class}::{$method}() called but not defined", $relFile);
            }
        }
    }
}

// Blade static calls
foreach (collectBladeFiles($root . '/resources/views') as $file) {
    $content = file_get_contents($file) ?: '';
    $relFile = rel($file, $root);
    if (preg_match_all('/\\\\App\\\\(Models|Support)\\\\([A-Za-z0-9_]+)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $class = 'App\\' . $match[1] . '\\' . $match[2];
            $method = $match[3];
            if (! class_exists($class)) {
                addIssue('view-static', "Class missing: {$class}::{$method}()", $relFile);
            } elseif (! methodExists($class, $method)) {
                addIssue('view-static', "{$class}::{$method}() called in view but not defined", $relFile);
            }
        }
    }
}

// ── 3. Eloquent scopes (::active(), custom scopes) ───────────────────────
echo "Checking Eloquent scope calls...\n";
$scopePattern = '/\b([A-Z][A-Za-z0-9_]*)::([a-z][A-Za-z0-9_]*)\s*\(/';

foreach (collectPhpFiles($root . '/app', $root . '/routes') as $file) {
    $content = file_get_contents($file) ?: '';
    $useMap = parseUseMap($content);
    $relFile = rel($file, $root);
    if (preg_match_all($scopePattern, $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $method = $match[2];
            if (in_array($method, $builderMethods, true)) {
                continue;
            }
            $class = resolveClass($match[1], $useMap);
            if ($class === null || ! str_starts_with($class, 'App\\Models\\')) {
                continue;
            }
            if (! class_exists($class)) {
                continue;
            }
            if (! methodExists($class, $method)) {
                addIssue('scope', "{$class}::{$method}() — no method or scope{$method}()", $relFile);
            }
        }
    }
}

// ── 4. Relationship methods in with()/load() — models only ──────────────
echo "Checking with()/load() relationships...\n";
$withPattern = '/->(?:with|load|loadMissing)\(\s*[\'"]([a-zA-Z][a-zA-Z0-9_.]*)[\'"]/';
$withArrayPattern = '/->(?:with|load|loadMissing)\(\s*\[(.*?)\]\s*\)/s';

foreach (collectPhpFiles($root . '/app/Models') as $file) {
    $content = file_get_contents($file) ?: '';
    $useMap = parseUseMap($content);
    $relFile = rel($file, $root);

    // Infer model from file if it's a Model
    $defaultModel = null;
    if (preg_match('/namespace App\\\\Models;\s+final class (\w+)/', $content, $nm)) {
        $defaultModel = 'App\\Models\\' . $nm[1];
    }

    $relations = [];
    if (preg_match_all($withPattern, $content, $wm)) {
        $relations = array_merge($relations, $wm[1]);
    }
    if (preg_match_all($withArrayPattern, $content, $am)) {
        foreach ($am[1] as $block) {
            if (preg_match_all('/[\'"]([a-zA-Z0-9_.]+)[\'"]/', $block, $rm)) {
                $relations = array_merge($relations, $rm[1]);
            }
        }
    }

    foreach (array_unique($relations) as $relationChain) {
        $parts = explode('.', $relationChain);
        $modelClass = $defaultModel;
        foreach ($parts as $part) {
            if ($modelClass === null) {
                break;
            }
            if (! class_exists($modelClass)) {
                break;
            }
            if (! method_exists($modelClass, $part)) {
                addIssue('relationship', "{$modelClass}::{$part}() relationship missing (chain: {$relationChain})", $relFile);
                break;
            }
            try {
                $instance = (new ReflectionClass($modelClass))->newInstanceWithoutConstructor();
                $returnType = (new ReflectionMethod($modelClass, $part))->getReturnType();
                $modelClass = null;
                if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
                    $modelClass = $returnType->getName();
                    if (! is_subclass_of($modelClass, 'Illuminate\\Database\\Eloquent\\Model') && ! is_subclass_of($modelClass, 'Illuminate\\Database\\Eloquent\\Relations\\Relation')) {
                        // For Relation, we'd need to invoke - skip deep chain
                        $modelClass = null;
                    }
                }
            } catch (Throwable) {
                break;
            }
        }
    }
}

// Controller-specific: with on Model::query patterns
foreach (collectPhpFiles($root . '/app/Http/Controllers') as $file) {
    $content = file_get_contents($file) ?: '';
    $useMap = parseUseMap($content);
    $relFile = rel($file, $root);
    if (preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::(?:query\(\)|with\()/', $content, $qm)) {
        // handled above via with patterns
    }
    if (preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::(?:query\(\)|with\()[^;]*->with\(\[(.*?)\]/s', $content, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $block) {
            $class = resolveClass($block[1], $useMap);
            if ($class === null || ! str_starts_with($class, 'App\\Models\\')) {
                continue;
            }
            if (preg_match_all('/[\'"]([a-zA-Z0-9_.]+)[\'"]/', $block[2], $rm)) {
                foreach ($rm[1] as $rel) {
                    $top = explode('.', $rel)[0];
                    if (class_exists($class) && ! method_exists($class, $top)) {
                        addIssue('relationship', "{$class}::{$top}() relationship missing", $relFile);
                    }
                }
            }
        }
    }
}

// ── 5. View variables: controller passes vs blade uses ────────────────────
echo "Checking view variables...\n";
$bladeSkipVars = [
    'errors', '__env', '__data', '__path', 'loop', 'slot', 'attributes', 'component',
    '__laravel_slot', '__currentLoopData', 'key', 'index', 'first', 'last', 'even', 'odd',
    'depth', 'parent', 'push', 'stack', 'section', 'csrf_token', 'app', 'auth', 'request',
    'session', 'config', 'route', 'url', 'old', 'this', 'true', 'false', 'null',
];

/** @var array<string, list<string>> viewName => variables passed */
$viewVars = [];

foreach (collectPhpFiles($root . '/app/Http/Controllers') as $file) {
    $content = file_get_contents($file) ?: '';
    $relFile = rel($file, $root);

    // view('name', [...])
    if (preg_match_all("/view\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*\[(.*?)\]\s*\)/s", $content, $vm, PREG_SET_ORDER)) {
        foreach ($vm as $match) {
            $viewName = str_replace('.', '/', $match[1]);
            $vars = [];
            if (preg_match_all("/['\"](\w+)['\"]\s*=>/", $match[2], $km)) {
                $vars = array_merge($vars, $km[1]);
            }
            if (! isset($viewVars[$viewName])) {
                $viewVars[$viewName] = [];
            }
            $viewVars[$viewName] = array_unique(array_merge($viewVars[$viewName], $vars));
        }
    }

    // compact('a', 'b')
    if (preg_match_all("/view\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*compact\s*\((.*?)\)\s*\)/s", $content, $cm, PREG_SET_ORDER)) {
        foreach ($cm as $match) {
            $viewName = str_replace('.', '/', $match[1]);
            $vars = [];
            if (preg_match_all("/['\"]?(\w+)['\"]?/", $match[2], $km)) {
                foreach ($km[1] as $v) {
                    if ($v !== 'compact' && $v !== '') {
                        $vars[] = $v;
                    }
                }
            }
            if (! isset($viewVars[$viewName])) {
                $viewVars[$viewName] = [];
            }
            $viewVars[$viewName] = array_unique(array_merge($viewVars[$viewName], $vars));
        }
    }
}

foreach (collectBladeFiles($root . '/resources/views') as $bladeFile) {
    $viewName = rel($bladeFile, $root . '/resources/views');
    $viewName = preg_replace('/\.blade\.php$/', '', $viewName) ?? $viewName;
    $content = file_get_contents($bladeFile) ?: '';

    $loopVars = [];
    if (preg_match_all('/@(?:foreach|forelse)\s*\([^)]*as\s+\$(\w+)/', $content, $lm)) {
        $loopVars = array_merge($loopVars, $lm[1]);
    }
    if (preg_match_all('/@php\s+(.*?)\s+@endphp/s', $content, $pm)) {
        foreach ($pm[1] as $phpBlock) {
            if (preg_match_all('/\$(\w+)\s*=/', $phpBlock, $am)) {
                $loopVars = array_merge($loopVars, $am[1]);
            }
        }
    }
    $loopVars = array_unique($loopVars);

    $used = [];
    if (preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $content, $um)) {
        foreach ($um[1] as $var) {
            if (! in_array($var, $bladeSkipVars, true) && ! in_array($var, $loopVars, true)) {
                $used[] = $var;
            }
        }
    }
    $used = array_unique($used);

    if (! isset($viewVars[$viewName])) {
        // View might get vars from View composers or shared - only warn on known controller views
        continue;
    }

    $passed = $viewVars[$viewName];
    foreach ($used as $var) {
        // Skip common Laravel injected vars patterns
        if (str_starts_with($var, '__')) {
            continue;
        }
        if (! in_array($var, $passed, true)) {
            addIssue('view', "View {$viewName}: \${$var} used but not passed by controller (passed: " . implode(', ', $passed) . ')', $viewName . '.blade.php');
        }
    }
}

// ── 6. Model instance methods called from controllers (heuristic) ─────────
echo "Checking model custom method calls...\n";
$knownModelMethods = ['getKey', 'getTable', 'getConnection', 'getAttribute', 'setAttribute', 'toArray', 'toJson', 'fresh', 'refresh', 'replicate', 'save', 'saveQuietly', 'update', 'delete', 'forceDelete', 'trashed', 'wasChanged', 'isDirty', 'getDirty', 'getChanges', 'getOriginal', 'only', 'except', 'makeHidden', 'makeVisible', 'append', 'setAppends', 'getRelations', 'relationLoaded', 'load', 'loadMissing', 'loadCount', 'loadSum', 'loadAvg', 'loadMax', 'loadMin', 'loadMorph', 'loadMorphCount', 'offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset', 'jsonSerialize', '__toString', 'getRouteKey', 'getRouteKeyName', 'resolveRouteBinding', 'resolveChildRouteBinding', 'getQueueableRelations', 'getQueueableId', 'getQueueableConnection', 'newCollection', 'newFromBuilder', 'newInstance', 'newQuery', 'newModelQuery', 'newQueryWithoutRelationships', 'newQueryWithoutScopes', 'newQueryForRestoration', 'qualifyColumn', 'getQualifiedKeyName', 'usesTimestamps', 'getCreatedAtColumn', 'getUpdatedAtColumn', 'getDeletedAtColumn', 'getIncrementing', 'getKeyName', 'getKeyType', 'getForeignKey', 'touch', 'touchOwners', 'updateTimestamps', 'setCreatedAt', 'setUpdatedAt', 'fromDateTime', 'asDateTime', 'asDate', 'asTimestamp', 'serializeDate', 'getDates', 'getCasts', 'getMutatedAttributes', 'hasGetMutator', 'hasSetMutator', 'mergeCasts', 'fill', 'forceFill', 'getFillable', 'getGuarded', 'isFillable', 'isGuarded', 'totallyGuarded', 'getHidden', 'getVisible', 'getAppends', 'hasAppended', 'getAttributes', 'setRawAttributes', 'syncOriginal', 'syncOriginalAttribute', 'syncOriginalAttributes', 'syncChanges', 'getRelation', 'setRelation', 'unsetRelation', 'setRelations', 'withoutRelations', 'relationResolver', 'resolveRelationUsing', 'hasRelation', 'isRelation', 'getMorphClass', 'getTable', 'getConnectionName', 'getConnection', 'setConnection', 'usesUniqueIds', 'newUniqueId', 'uniqueIds', 'booted', 'booting', 'bootIfNotBooted', 'clearBootedModels', 'isIgnoringTouch', 'withoutTouching', 'withoutTouchingOn', 'addGlobalScope', 'hasGlobalScope', 'getGlobalScope', 'getGlobalScopes', 'resolveRelationUsing'];

foreach (collectPhpFiles($root . '/app/Http/Controllers', $root . '/app/Services') as $file) {
    $content = file_get_contents($file) ?: '';
    $useMap = parseUseMap($content);
    $relFile = rel($file, $root);

    // Type hints: function foo(Barang $barang)
    $typedVars = [];
    if (preg_match_all('/function\s+\w+\([^)]*\$(\w+)\s+(\w+)/', $content, $tm, PREG_SET_ORDER)) {
        foreach ($tm as $t) {
            $class = resolveClass($t[2], $useMap);
            if ($class !== null && str_starts_with($class, 'App\\Models\\')) {
                $typedVars[$t[1]] = $class;
            }
        }
    }
    // Route model binding in signature: Barang $barang
    if (preg_match_all('/(?:public|protected|private)\s+function\s+\w+\([^)]*\\\\App\\\\Models\\\\(\w+)\s+\$(\w+)/', $content, $tm2, PREG_SET_ORDER)) {
        foreach ($tm2 as $t) {
            $typedVars[$t[2]] = 'App\\Models\\' . $t[1];
        }
    }

    if (preg_match_all('/\$(\w+)->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $content, $im, PREG_SET_ORDER)) {
        foreach ($im as $match) {
            $var = $match[1];
            $method = $match[2];
            if (in_array($method, $knownModelMethods, true)) {
                continue;
            }
            if (! isset($typedVars[$var])) {
                continue;
            }
            $class = $typedVars[$var];
            if (! class_exists($class)) {
                continue;
            }
            if (! method_exists($class, $method)) {
                addIssue('model', "\${$var}->{$method}() on {$class} — method missing", $relFile);
            }
        }
    }
}

// ── Report ────────────────────────────────────────────────────────────────
$byCategory = [];
foreach ($issues as $issue) {
    $byCategory[$issue['category']] = ($byCategory[$issue['category']] ?? 0) + 1;
}

$failCategories = ['route', 'static', 'view-static', 'scope', 'model'];
$failures = array_values(array_filter($issues, static fn (array $i): bool => in_array($i['category'], $failCategories, true)));
$warnings = array_values(array_filter($issues, static fn (array $i): bool => ! in_array($i['category'], $failCategories, true)));

echo "\n";
if ($reportPath !== null) {
    $lines = array_map(static fn ($i) => "[{$i['category']}] {$i['message']}" . (isset($i['file']) ? " ({$i['file']})" : ''), $issues);
    file_put_contents($root . '/' . ltrim($reportPath, '/'), implode(PHP_EOL, $lines) . PHP_EOL);
    echo "Report written to {$reportPath}\n";
}

if ($failures === []) {
    echo 'PASS — deep audit found 0 blocking issues';
    if ($warnings !== []) {
        echo ' (' . count($warnings) . ' view/relationship warnings)';
    }
    echo "\n";
    exit(0);
}

echo 'FAIL — ' . count($failures) . ' blocking issue(s)';
if ($warnings !== []) {
    echo ', ' . count($warnings) . ' warning(s)';
}
echo ":\n";
foreach ($byCategory as $cat => $count) {
    echo "  [{$cat}] {$count}\n";
}
echo "\n";

foreach ($failures as $issue) {
    $loc = isset($issue['file']) ? " ({$issue['file']})" : '';
    echo "  • [{$issue['category']}] {$issue['message']}{$loc}\n";
}

if ($warnings !== []) {
    echo "\nWarnings (non-blocking):\n";
    foreach (array_slice($warnings, 0, 15) as $issue) {
        $loc = isset($issue['file']) ? " ({$issue['file']})" : '';
        echo "  • [{$issue['category']}] {$issue['message']}{$loc}\n";
    }
    if (count($warnings) > 15) {
        echo '  ... and ' . (count($warnings) - 15) . " more warnings\n";
    }
}

exit($failures === [] ? 0 : 1);

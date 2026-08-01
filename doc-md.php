#!/usr/bin/env php
<?php
/**
 * Generate Markdown documentation from rustdoc JSON output.
 *
 * Usage: php doc-md.php
 *
 * Requires: nightly toolchain (auto-installed if missing), cargo, php with JSON support.
 * Output:  target/doc-md/
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function cmd(string $cmd, bool $quiet = false): string {
    $output = [];
    $result = 0;
    exec($cmd . ' 2>&1', $output, $result);
    $out = implode("\n", $output);
    if (!$quiet && $result !== 0) {
        fwrite(STDERR, "Command failed (exit $result): $cmd\n$out\n");
    }
    return $out;
}

function ensure_nightly(): void {
    echo "Checking nightly toolchain... ";
    $out = cmd('rustup show active-toolchain');
    if (preg_match('/nightly/', $out)) {
        echo "Active.\n";
        return;
    }
    $list = cmd('rustup toolchain list');
    if (!preg_match('/nightly/', $list)) {
        echo "\nInstalling nightly toolchain...";
        cmd('rustup install nightly');
        echo "Done.\n";
    } else {
        echo "Available.\n";
    }
}

// ---------------------------------------------------------------------------
// Markdown generation
// ---------------------------------------------------------------------------

class DocGenerator {
    private array $index;
    private int $rootId;
    private string $crateName;
    private string $crateVersion;
    private string $outDir;
    private array $itemPaths = [];     // id -> absolute .md path (from crate root)
    private array $generatedItems = []; // id -> bool (track generated pages)
    private string $currentPath = '';  // current module path during rendering
    private bool $quoteDescriptions = true;
    private bool $signatureBlocks = true;

    public function __construct(array $data, string $outDir, string $crateName, bool $quoteDescriptions = true, bool $signatureBlocks = true) {
        $this->index = $data['index'];
        $this->rootId = (int)($data['root'] ?? 0);
        $this->crateName = $crateName ?? 'unknown';
        $this->crateVersion = $data['crate_version'] ?? 'unknown';
        $this->outDir = $outDir;
        $this->quoteDescriptions = $quoteDescriptions;
        $this->signatureBlocks = $signatureBlocks;
    }

    // ---- File I/O ----

    private function writeMd(string $path, string $content): void {
        $fullPath = $this->outDir . '/' . $path;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($fullPath, $content);
    }

    // ---- Item type helpers ----

    private function getItemType(array $item): string {
        return array_keys($item['inner'] ?? [])[0] ?? 'unknown';
    }

    private function isPublic(array $item): bool {
        return ($item['visibility'] ?? '') === 'public';
    }

    /**
     * Render an item title string, e.g. "Struct `Foo<T>`".
     */
    private function renderTitle(array $item, string $type): string {
        $name = $this->getItemName($item, $type);
        $label = match ($type) {
            'struct'     => 'Struct',
            'enum'       => 'Enum',
            'trait'      => 'Trait',
            'function'   => 'Function',
            'macro'      => 'Macro',
            'constant'   => 'Constant',
            'type_alias' => 'Type Alias',
            'use'        => 'Use',
            default      => ucfirst($type),
        };
        // Append generics if present
        $generics = $item['generics'] ?? null;
        if (!$generics) {
            $inner = $item['inner'] ?? [];
            $generics = ($inner[$type] ?? [])['generics'] ?? null;
        }
        $genPart = '';
        if ($generics && !empty($generics['params'])) {
            $params = array_filter($generics['params'], fn($p) => !($p['kind']['type']['is_synthetic'] ?? false));
            $names = array_map(fn($p) => $p['name'] ?? '?', $params);
            if ($names) $genPart = '<' . implode(', ', $names) . '>';
        }
        return "$label `$name$genPart`";
    }

    /**
     * Extract generic parameter names from a generics dict.
     */
    private function getGenericParamNames(array $generics): array {
        if (empty($generics['params'])) return [];
        return array_map(fn($p) => $p['name'] ?? '?', $generics['params']);
    }

    /**
     * Append a signature line as code block or heading.
     */
    private function appendSignature(array &$lines, string $sig, int $headingLevel = 3): void {
        $prefix = str_repeat('#', $headingLevel);
        if ($this->signatureBlocks) {
            $lines[] = '```rust';
            $lines[] = $sig;
            $lines[] = '```';
        } else {
            $lines[] = "$prefix `$sig`";
        }
    }

    /**
     * Extract where-clause lines from a generics dict.
     */
    private function getWhereLines(array $generics): array {
        $whereLines = [];
        if (!empty($generics['where_predicates'])) {
            foreach ($generics['where_predicates'] as $pred) {
                $bp = $pred['bound_predicate'] ?? null;
                if ($bp) {
                    $typeName = $this->renderTypeName($bp['type'] ?? null);
                    $bounds = array_map(fn($b) => $this->renderTraitBound($b), $bp['bounds'] ?? []);
                    if ($bounds) {
                        $whereLines[] = "    $typeName: " . implode(' + ', $bounds);
                    }
                }
            }
        }
        return $whereLines;
    }

    private function getItemName(array $item, string $type): string {
        $name = $item['name'] ?? null;
        if ($name) return $name;
        if ($type === 'use') {
            return $item['inner']['use']['name'] ?? 'unnamed';
        }
        return 'unnamed';
    }

    private function getFirstLineOfDocs(array $item): string {
        $docs = $this->resolveDocLinks($item['docs'] ?? '', $item['links'] ?? []);
        if (!$docs) return '';
        $lines = explode("\n", trim($docs));
        return trim($lines[0]);
    }

    // ---- Path registration ----

    /**
     * Build the file name for an item's standalone page.
     * e.g. struct.BuildError.md, fn.build_str.md
     */
    private function itemNameToPath(array $item): string {
        $type = $this->getItemType($item);
        $name = $this->getItemName($item, $type);
        return strtolower("$type.$name.md");
    }

    private function registerItem(int $id, string $path): void {
        // Prefer shorter paths (more public locations)
        if (!isset($this->itemPaths[$id]) || strlen($path) < strlen($this->itemPaths[$id])) {
            $this->itemPaths[$id] = $path;
        }
    }

    private function idToLink(int $id): string {
        $abs = $this->itemPaths[$id] ?? '';
        if (!$abs) return '';
        return $this->relativePath($this->currentPath, $abs);
    }

    /**
     * Compute relative path from $from (directory) to $to (file).
     */
    private function relativePath(string $from, string $to): string {
        $fromParts = $from ? explode('/', trim($from, '/')) : [];
        $toParts = explode('/', $to);
        array_pop($toParts); // remove filename for directory comparison

        // Find common prefix
        $common = 0;
        $min = min(count($fromParts), count($toParts));
        while ($common < $min && $fromParts[$common] === $toParts[$common]) {
            $common++;
        }

        // Go up from $from to the common ancestor, then down to $to
        $up = count($fromParts) - $common;
        $down = $to; // full path from common ancestor

        if ($common > 0) {
            // Strip the common prefix from $to
            $down = implode('/', array_slice(explode('/', $to), $common));
        }

        return str_repeat('../', $up) . $down;
    }

    /**
     * Wrap doc lines as a block quote (prefix each line with "> ").
     */
    private function asBlockQuote(string $docs): string {
        if (!$this->quoteDescriptions) return $docs;
        return preg_replace('/^/m', '> ', $docs);
    }

    /**
     * Shift markdown headings down by $depth levels so doc-comment headings nest properly.
     */
    private function shiftHeadings(string $docs, int $depth = 1): string {
        $prefix = str_repeat('#', $depth);
        return preg_replace('/^([#]{1,6})\s/m', "$prefix$1 ", $docs);
    }

    private function resolveDocLinks(string $docs, array $links, int $depth = 1): string {
        $docs = $this->hideBoilerplate($docs);
        $docs = $this->shiftHeadings($docs, $depth);
        if (empty($links)) return $docs;
        foreach ($links as $label => $id) {
            $link = $this->idToLink((int)$id);
            if ($link) {
                // Replace [label](any_url) with [label](resolved_link)
                $docs = preg_replace(
                    '/\[' . preg_quote($label, '/') . '\]\([^)]*\)/',
                    "[$label]($link)",
                    $docs
                );
                // Handle bare [label] references (no parentheses)
                $docs = str_replace("[$label]", "[$label]($link)", $docs);
            }
        }
        // Fallback: resolve remaining Rust-style paths like crate::module::Item
        $docs = preg_replace_callback(
            '/\[([^\]]+)\]\((crate::[\w::]+(?:\(\))?)\)/',
            fn($m) => $this->tryResolveRustPath($m[1], $m[2]),
            $docs
        );
        // Also handle definition-style links: [label]: crate::path
        $docs = preg_replace_callback(
            '/^\[([^\]]+)\]:\s*(crate::[\w::]+(?:\(\))?)$/m',
            fn($m) => $this->tryResolveRustPathDef($m[1], $m[2]),
            $docs
        );
        return $docs;
    }

    /**
     * Remove hidden boilerplate lines (starting with #) from code blocks.
     */
    private function hideBoilerplate(string $docs): string {
        return preg_replace_callback("/```[\s\S]*?```/", function($m) {
            $block = $m[0];
            $lines = explode("\n", $block);
            $result = [];
            for ($i = 0; $i < count($lines); $i++) {
                $line = $lines[$i];
                // Skip lines starting with # (but not the opening/closing ```)
                if ($i > 0 && $i < count($lines) - 1 && preg_match("/^\s*#/", $line)) {
                    continue;
                }
                $result[] = $line;
            }
            return implode("\n", $result);
        }, $docs);
    }

    /**
     * Try to resolve a Rust-style path (e.g. crate::documentation::Integer or View::new) to a file link.
     */
    private function tryResolveRustPath(string $label, string $path): string {
        $segments = explode('::', $path);

        // Try progressively shorter suffixes to handle Item::method paths
        for ($i = count($segments); $i > 0; $i--) {
            $slice = array_slice($segments, 0, $i);
            $itemName = end($slice);
            foreach ($this->itemPaths as $id => $filePath) {
                $item = $this->index[(string)$id] ?? null;
                if (!$item) continue;
                if (($item['name'] ?? '') === $itemName && $this->isPublic($item)) {
                    $link = $this->idToLink((int)$id);
                    if ($link) return "[$label]($link)";
                }
            }
        }
        // Could not resolve, leave as-is
        return "[$label]($path)";
    }

    /**
     * Resolve definition-style link: [label]: crate::path → [label]: resolved_path
     */
    private function tryResolveRustPathDef(string $label, string $path): string {
        $segments = explode('::', $path);
        for ($i = count($segments); $i > 0; $i--) {
            $slice = array_slice($segments, 0, $i);
            $itemName = end($slice);
            foreach ($this->itemPaths as $id => $filePath) {
                $item = $this->index[(string)$id] ?? null;
                if (!$item) continue;
                if (($item['name'] ?? '') === $itemName && $this->isPublic($item)) {
                    $link = $this->idToLink((int)$id);
                    if ($link) return "[$label]: $link";
                }
            }
        }
        return "[$label]: $path";
    }

    // ---- Re-export expansion ----

    private function expandModuleChildren(array $module): array {
        $seen = [];
        $children = [];
        foreach ($module['inner']['module']['items'] ?? [] as $id) {
            $item = $this->index[(string)$id] ?? null;
            if (!$item) continue;
            $type = $this->getItemType($item);

            if ($type === 'use') {
                $useData = $item['inner']['use'] ?? [];
                if (($useData['is_glob'] ?? false)) {
                    $targetId = $useData['id'] ?? null;
                    if ($targetId) {
                        $targetModule = $this->index[(string)$targetId] ?? null;
                        if ($targetModule) {
                            foreach ($this->expandModuleChildren($targetModule) as $child) {
                                $childId = $child['id'];
                                if (!isset($seen[$childId])) {
                                    $seen[$childId] = true;
                                    $children[] = $child;
                                }
                            }
                        }
                    }
                } else {
                    $targetId = $useData['id'] ?? null;
                    if ($targetId) {
                        $target = $this->index[(string)$targetId] ?? null;
                        if ($target && $this->isPublic($target)) {
                            if (!isset($seen[$target['id']])) {
                                $seen[$target['id']] = true;
                                $children[] = $target;
                            }
                        }
                    }
                }
            } else {
                $itemId = $item['id'];
                if (!isset($seen[$itemId])) {
                    $seen[$itemId] = true;
                    $children[] = $item;
                }
            }
        }
        return $children;
    }

    // ---- First pass: register all public item paths ----

    private function registerModulePaths(array $module, string $modulePath): void {
        foreach ($this->expandModuleChildren($module) as $item) {
            $type = $this->getItemType($item);

            if ($type === 'module') {
                $subPath = $modulePath . ($item['name'] ?? '') . '/';
                $this->registerItem((int)$item['id'], $subPath . 'index.md');
                $this->registerModulePaths($item, $subPath);
            } elseif ($this->isPublic($item)) {
                $this->registerItem((int)$item['id'], $modulePath . $this->itemNameToPath($item));
            }
        }
    }

    // ---- Main entry point ----

    public function generate(): void {

        $rootItem = $this->index[(string)$this->rootId] ?? null;

        if (!$rootItem) {
            fwrite(STDERR, "Could not find root module\n");
            exit(1);
        }

        // First pass: register all public item paths
        $this->registerModulePaths($rootItem, '');

        // Generate crate index
        $this->currentPath = '';
        $this->writeMd('index.md', $this->generateCrateIndex($rootItem));

        // Generate item pages for crate-root items (re-exported items, functions, macros, etc.)
        $this->generateItemPagesForModule($rootItem, '');

        // Generate module pages recursively (skip root — crate index already generated)
        $this->generateModuleChildren($rootItem, '');
    }

    // ---- Crate index page ----

    private function generateCrateIndex(array $root): string {
        $lines = [];
        $docs = $this->resolveDocLinks($root['docs'] ?? '', $root['links'] ?? []);

        $lines[] = "# {$this->crateName} v{$this->crateVersion}";
        $lines[] = '';
        if ($docs) { $lines[] = $this->asBlockQuote($docs); $lines[] = ''; }

        $sections = [
            'Modules'      => ['module'],
            'Structs'      => ['struct'],
            'Enums'        => ['enum'],
            'Traits'       => ['trait'],
            'Functions'    => ['function'],
            'Macros'       => ['macro'],
            'Constants'    => ['constant'],
            'Type Aliases' => ['type_alias'],
        ];

        foreach ($sections as $title => $types) {
            $items = $this->getModuleChildren($root, $types);
            if (empty($items)) continue;
            $lines[] = "## $title";
            $lines[] = '';
            $this->appendTable($items, $lines);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function getModuleChildren(array $module, array $types): array {
        $result = [];
        foreach ($this->expandModuleChildren($module) as $item) {
            if (!in_array($this->getItemType($item), $types, true)) continue;
            if ($this->getItemType($item) === 'module') {
                $result[] = $item;
            } elseif ($this->isPublic($item)) {
                $result[] = $item;
            }
        }
        return $result;
    }

    // ---- Summary table ----

    /**
     * Render a markdown table: Item | Description
     */
    private function appendTable(array $items, array &$lines): void {
        // Sort items alphabetically by name
        usort($items, fn($a, $b) => ($a['name'] ?? '') <=> ($b['name'] ?? ''));
        $lines[] = 'Item | Description';
        $lines[] = '-|-';
        foreach ($items as $item) {
            // Skip items registered in a sibling/descendant module (not current or ancestor)
            $itemPath = $this->itemPaths[$item['id']] ?? '';
            if ($itemPath && $this->currentPath) {
                $itemDir = dirname($itemPath);
                $currDir = rtrim($this->currentPath, '/');
                // Show item if registered in current dir, in an ancestor dir, or is a child dir
                if ($itemDir !== $currDir && strpos($currDir, $itemDir) !== 0 && strpos($itemDir, $currDir . '/') !== 0) {
                    continue;
                }
            }
            $type = $this->getItemType($item);
            $name = $this->getItemName($item, $type);
            $link = $this->idToLink((int)$item['id']);

            if ($type === 'module') {
                $modLink = $name . '/index.md';
                $desc = $this->getFirstLineOfDocs($item);
                $lines[] = "[$name]($modLink) | $desc";
            } else {
                $depr = $item['deprecation'] ? ' *~~deprecated~~*' : '';
                $desc = $this->getFirstLineOfDocs($item);
                $lines[] = "[$name]($link)$depr | $desc";
            }
        }
    }

    // ---- Parent module helpers ----

    /**
     * Get the parent module's path from a child module path.
     * e.g. 'internals/binary/' -> 'internals/'
     *      'documentation/' -> ''
     */
    private function parentModulePath(string $modulePath): string {
        $parts = explode('/', trim($modulePath, '/'));
        array_pop($parts); // remove current module
        return $parts ? implode('/', $parts) . '/' : '';
    }

    /**
     * Get the label for the parent module back-link.
     */
    private function parentModuleLabel(string $modulePath, string $currentName): string {
        $parts = explode('/', trim($modulePath, '/'));
        array_pop($parts);
        if (empty($parts)) return "Crate `$this->crateName`";
        return "Module `" . end($parts) . "`";
    }

    // ---- Generate item pages for a module's children ----

    private function generateItemPagesForModule(array $module, string $modulePath): void {
        foreach ($this->expandModuleChildren($module) as $item) {
            $type = $this->getItemType($item);
            if ($type !== 'module' && $this->isPublic($item)) {
                $this->generateItemPage($item, $modulePath);
            }
        }
    }

    // ---- Generate sub-module pages (without writing the parent module's own page) ----

    private function generateModuleChildren(array $module, string $modulePath): void {
        foreach ($this->expandModuleChildren($module) as $item) {
            if ($this->getItemType($item) === 'module') {
                $subName = $item['name'] ?? '';
                $this->generateModule($item, $modulePath . $subName . '/');
            }
        }
    }

    // ---- Module page ----

    private function generateModule(array $module, string $modulePath): void {
        $this->currentPath = $modulePath;
        $name = $module['name'] ?? '';
        $lines = [];

        // Back-link to parent module (skip for crate root)
        if ($modulePath) {
            $parentPath = $this->parentModulePath($modulePath);
            $parentLabel = $this->parentModuleLabel($modulePath, $name);
            $backLink = $this->relativePath($modulePath, $parentPath . 'index.md');
            $lines[] = "[← $parentLabel]($backLink)";
            $lines[] = '';
        }

        $lines[] = $modulePath ? "# Module `$name`" : "# Crate `$name`";
        $lines[] = '';

        $docs = $this->resolveDocLinks($module['docs'] ?? '', $module['links'] ?? []);
        if ($docs) { $lines[] = $this->asBlockQuote($docs); $lines[] = ''; }

        // Collect children by category (following re-exports)
        $subModules = [];
        $publicItems = [];
        $privateItems = [];

        foreach ($this->expandModuleChildren($module) as $item) {
            $type = $this->getItemType($item);
            if ($type === 'module') {
                $subModules[] = $item;
            } elseif ($this->isPublic($item)) {
                $publicItems[] = $item;
                // Generate standalone page for each item
                $this->generateItemPage($item, $modulePath);
            } else {
                $privateItems[] = $item;
            }
        }

        // Sub-modules
        if ($subModules) {
            $lines[] = '## Modules';
            $lines[] = '';
            $this->appendTable($subModules, $lines);
            $lines[] = '';

            foreach ($subModules as $sub) {
                $subName = $sub['name'] ?? '';
                $this->generateModule($sub, $modulePath . $subName . '/');
            }
            // Restore currentPath after recursive calls
            $this->currentPath = $modulePath;
        }

        // Group public items by type
        $groups = [
            'Structs'      => ['struct'],
            'Enums'        => ['enum'],
            'Traits'       => ['trait'],
            'Functions'    => ['function'],
            'Macros'       => ['macro'],
            'Constants'    => ['constant'],
            'Type Aliases' => ['type_alias'],
        ];

        foreach ($groups as $title => $types) {
            $groupItems = array_values(array_filter($publicItems, fn($it) =>
                in_array($this->getItemType($it), $types, true)));
            if (empty($groupItems)) continue;

            $lines[] = "## $title";
            $lines[] = '';
            $this->appendTable($groupItems, $lines);
            $lines[] = '';
        }

        // Inline private items (still full detail since they have no separate page)
        if ($privateItems) {
            $lines[] = '## Private Items';
            $lines[] = '';
            foreach ($privateItems as $item) {
                $this->appendDetail($item, $lines);
            }
            $lines[] = '';
        }

        $this->writeMd($modulePath . 'index.md', implode("\n", $lines));
    }

    // ---- Standalone item page ----

    private function generateItemPage(array $item, string $modulePath): void {
        $itemId = (int)$item['id'];
        if (isset($this->generatedItems[$itemId])) return; // already generated
        $this->generatedItems[$itemId] = true;
        $this->currentPath = $modulePath;
        $type = $this->getItemType($item);
        $name = $this->getItemName($item, $type);
        $lines = [];

        // Back link to parent module
        $backLink = $modulePath
            ? $this->relativePath($modulePath, $modulePath . 'index.md')
            : 'index.md';
        $backLabel = $modulePath ? "Module `$name`" : "Crate `$this->crateName`";
        // Actually the back link should point to the containing module, not re-use $name
        // We'll figure this out from the modulePath
        if ($modulePath) {
            // Extract parent module name from path
            $parts = explode('/', trim($modulePath, '/'));
            $parentName = end($parts);
            $backLabel = "Module `$parentName`";
        } else {
            $backLabel = "Crate `$this->crateName`";
        }
        $lines[] = "[← $backLabel]($backLink)";
        $lines[] = '';

        // Title
        $depr = $item['deprecation'] ? ' >~~Deprecated~~<' : '';
        $title = $this->renderTitle($item, $type);
        $lines[] = "# $title$depr";
        $lines[] = '';

        // Function signature goes before docs
        if ($type === 'function') $this->renderFunctionDetails($item, $lines);

        // Full docs
        $docs = $this->resolveDocLinks($item['docs'] ?? '', $item['links'] ?? []);
        if ($docs) { $lines[] = $this->asBlockQuote($docs); $lines[] = ''; }

        // Type-specific details
        if ($type === 'struct') $this->renderStructDetails($item, $lines);
        elseif ($type === 'enum') $this->renderEnumDetails($item, $lines);
        elseif ($type === 'trait') $this->renderTraitDetails($item, $lines);

        $this->writeMd($modulePath . $this->itemNameToPath($item), implode("\n", $lines));
    }

    // ---- Item detail (for inline private items) ----

    private function appendDetail(array $item, array &$lines): void {
        $type = $this->getItemType($item);

        $depr = $item['deprecation'] ? ' >~~Deprecated~~<' : '';
        $title = $this->renderTitle($item, $type);
        $lines[] = "### $title$depr";
        $lines[] = '';

        $docs = $this->resolveDocLinks($item['docs'] ?? '', $item['links'] ?? []);
        if ($docs) { $lines[] = $docs; $lines[] = ''; }

        if ($type === 'struct') $this->renderStructDetails($item, $lines);
        elseif ($type === 'enum') $this->renderEnumDetails($item, $lines);
        elseif ($type === 'trait') $this->renderTraitDetails($item, $lines);
        elseif ($type === 'function') $this->renderFunctionDetails($item, $lines);
    }

    // ---- Signature rendering ----

    private function renderTraitBound(array $bound): string {
        $tb = $bound['trait_bound'] ?? null;
        if (!$tb) return '?';
        $trait = $tb['trait'] ?? null;
        if (!$trait) return '?';
        $path = $trait['path'] ?? '?';
        $args = $trait['args'] ?? null;
        if ($args && isset($args['angle_bracketed']['args'])) {
            $types = array_map(fn($a) => $this->renderTypeName($a['type'] ?? null), $args['angle_bracketed']['args']);
            return $path . '<' . implode(', ', $types) . '>';
        }
        return $path;
    }

    // ---- Struct details ----

    private function renderStructDetails(array $item, array &$lines): void {
        $struct = $item['inner']['struct'] ?? [];
        $kind = $struct['kind'] ?? [];

        // Fields
        if (isset($kind['plain']['fields']) && !empty($kind['plain']['fields'])) {
            $lines[] = '## Fields';
            $lines[] = '';
            foreach ($kind['plain']['fields'] as $fieldId) {
                $field = $this->index[(string)$fieldId] ?? null;
                if (!$field) continue;
                $fname = $field['name'] ?? 'unnamed';
                $ftype = $this->renderTypeName($field['inner']['struct_field'] ?? null);
                $fdocs = $this->resolveDocLinks($field['docs'] ?? '', $field['links'] ?? [], 3);
                $sig = "$fname: $ftype";

                $this->appendSignature($lines, $sig);
                $lines[] = '';
                if ($fdocs) { $lines[] = $fdocs; $lines[] = ''; }
            }
        }

        // Impl blocks: separate intrinsic methods from trait impls
        $impls = $struct['impls'] ?? [];
        if (!is_array($impls) || empty($impls)) return;

        $implIds = isset($impls['impl']) ? $impls['impl'] : $impls;
        $intrinsicImpls = [];
        $traitNames = [];
        $structName = $item['name'] ?? 'Struct';

        foreach ($implIds as $implId) {
            $impl = $this->index[(string)$implId] ?? null;
            if (!$impl) continue;
            $implData = $impl['inner']['impl'] ?? [];
            $trait = $implData['trait'] ?? null;
            $implItems = $implData['items'] ?? [];
            $generics = $implData['generics'] ?? [];

            if ($trait) {
                // Trait impl — collect trait name if resolvable
                $tid = is_array($trait) ? ($trait['id'] ?? null) : $trait;
                if (isset($this->index[(string)$tid])) {
                    $traitItem = $this->index[(string)$tid];
                    $traitNames[] = $traitItem['name'] ?? '?';
                }
            } else {
                // Intrinsic impl — collect methods
                $methods = [];
                foreach ($implItems as $itemId) {
                    $assoc = $this->index[(string)$itemId] ?? null;
                    if ($assoc && ($assoc['visibility'] ?? '') === 'public') {
                        $methods[] = $assoc;
                    }
                }
                if ($methods) {
                    $intrinsicImpls[] = [
                        'generics' => $generics,
                        'methods' => $methods,
                        'docs' => $impl['docs'] ?? '',
                        'links' => $impl['links'] ?? [],
                    ];
                }
            }
        }

        // Intrinsic methods grouped by impl block
        if ($intrinsicImpls) {
            $lines[] = '## Implementations';
            $lines[] = '';
            foreach ($intrinsicImpls as $implBlock) {
                $this->renderImplHeader($structName, $implBlock['generics'], $lines);
                $implDocs = $this->resolveDocLinks($implBlock['docs'], $implBlock['links'], 3);
                if ($implDocs) { $lines[] = $implDocs; $lines[] = ''; }
                $sorted = $implBlock['methods'];
                usort($sorted, fn($a, $b) => ($a['name'] ?? '') <=> ($b['name'] ?? ''));
                foreach ($sorted as $item) {
                    $itype = $this->getItemType($item);
                    if ($itype === 'function') {
                        $this->renderMethodDetails($item, $lines);
                    } elseif ($itype === 'assoc_const') {
                        $this->renderConstDetails($item, $lines);
                    }
                }
            }
        }

        // Implemented traits
        if ($traitNames) {
            $lines[] = '## Trait Implementations';
            $lines[] = '';
            foreach (array_unique($traitNames) as $tn) {
                $lines[] = "- `$tn`";
            }
            $lines[] = '';
        }
    }

    private function renderImplHeader(string $structName, array $generics, array &$lines): void {
        $header = "impl";
        $params = $this->getGenericParamNames($generics);
        if ($params) $header .= '<' . implode(', ', $params) . '>';
        $header .= " $structName";

        $whereLines = $this->getWhereLines($generics);

        $lines[] = '### Block';
        $lines[] = '```rust';
        $lines[] = $header;
        if ($whereLines) {
            $lines[] = 'where';
            foreach ($whereLines as $w) $lines[] = $w;
        }
        $lines[] = '```';
        $lines[] = '';
    }

    // ---- Method details ----

    private function renderMethodDetails(array $method, array &$lines): void {
        $name = $method['name'] ?? 'unnamed';
        $func = $method['inner']['function'] ?? [];
        $sig = $func['sig'] ?? [];

        // Parameters
        $params = [];
        foreach ($sig['inputs'] ?? [] as $input) {
            if (isset($input[0]) && $input[0] === 'self') {
                $params[] = $input[0];
                continue;
            }
            $pname = $input[0] ?? '_';
            $ptype = $this->renderTypeName($input[1] ?? null);
            $params[] = "$pname: $ptype";
        }

        // Return type
        $ret = $this->renderTypeName($sig['output'] ?? null);

        // Signature line
        $sigLine = "fn $name(" . implode(', ', $params) . ")";
        if ($ret !== '()') $sigLine .= " -> $ret";

        $this->appendSignature($lines, $sigLine, 4);
        $lines[] = '';

        // Docs
        $docs = $this->resolveDocLinks($method['docs'] ?? '', $method['links'] ?? [], 3);
        if ($docs) { $lines[] = $docs; $lines[] = ''; }
    }

    // ---- Constant details ----

    private function renderConstDetails(array $const, array &$lines): void {
        $name = $const['name'] ?? 'unnamed';
        $cdata = $const['inner']['assoc_const'] ?? [];
        $ctype = $this->renderTypeName($cdata['type'] ?? null);

        $opt_value = isset($cdata['value']) ? "= {$cdata['value']}" : "";
        $lines[] = "#### `const $name: $ctype $opt_value`";
        $lines[] = '';

        // Docs
        $docs = $this->resolveDocLinks($const['docs'] ?? '', $const['links'] ?? [], 3);
        if ($docs) { $lines[] = $docs; $lines[] = ''; }
    }

    // ---- Type name rendering ----

    private function renderTypeName(?array $ty): string {
        if (!$ty) return '()';
        if (isset($ty['primitive'])) return $ty['primitive'];
        if (isset($ty['generic'])) return $ty['generic'];
        if (isset($ty['resolved_path'])) {
            $p = $ty['resolved_path'];
            $name = $p['path'] ?? '?';
            $pid = (int)($p['id'] ?? 0);
            // Use public path if available
            if ($pid && isset($this->itemPaths[$pid])) {
                $pubPath = $this->itemPaths[$pid];
                // Extract module path + item name from public path
                $parts = explode('/', trim($pubPath, '/'));
                array_pop($parts); // remove item file
                $modPath = $parts ? implode('::', $parts) : 'crate';
                // Get original item name from index
                $itemTypeName = $this->index[$pid]['name'] ?? $name;
                $name = $modPath . '::' . $itemTypeName;
            }
            $args = $p['args'] ?? null;
            if ($args && isset($args['angle_bracketed']['args'])) {
                $types = array_map(fn($a) => $this->renderTypeName($a['type'] ?? null), $args['angle_bracketed']['args']);
                return $name . '<' . implode(', ', $types) . '>';
            }
            return $name;
        }
        if (isset($ty['borrowed_ref'])) {
            $br = $ty['borrowed_ref'];
            $lifetime = $br['lifetime'] ?? '';
            $mut = ($br['is_mutable'] ?? false) ? 'mut ' : '';
            return "&$lifetime$mut" . ($lifetime ? ' ' : '') . $this->renderTypeName($br['type'] ?? null);
        }
        if (isset($ty['mutable_ref'])) {
            return '&mut ' . $this->renderTypeName($ty['mutable_ref']['type'] ?? null);
        }
        if (isset($ty['boxed'])) {
            return 'Box<' . $this->renderTypeName($ty['boxed']) . '>';
        }
        if (isset($ty['tuple'])) {
            $elems = array_map(fn($e) => $this->renderTypeName($e), $ty['tuple']);
            return '(' . implode(', ', $elems) . ')';
        }
        if (isset($ty['function'])) {
            return 'fn(...)';
        }
        if (isset($ty['array'])) {
            $arr = is_array($ty['array']) ? $ty['array'] : $ty;
            return '[' . $this->renderTypeName($arr['type'] ?? null) . '; ' . ($arr['len'] ?? '?') . ']';
        }
        if (isset($ty['slice'])) {
            return '[' . $this->renderTypeName($ty['slice'] ?? null) . ']';
        }
        if (isset($ty['param'])) {
            return $ty['param'] ?? '?';
        }
        if (isset($ty['associated_type'])) {
            return $ty['associated_type'] ?? '?';
        }
        if (isset($ty['impl_trait']) && is_array($ty['impl_trait']) && !empty($ty['impl_trait'])) {
            $bound = $ty['impl_trait'][0]['trait_bound'] ?? null;
            if ($bound) {
                $trait = $bound['trait'] ?? null;
                if ($trait) {
                    $path = $trait['path'] ?? '?';
                    $args = $trait['args'] ?? null;
                    if ($args && isset($args['parenthesized']['inputs'])) {
                        $inputs = array_map(fn($i) => $this->renderTypeName($i), $args['parenthesized']['inputs']);
                        $output = $args['parenthesized']['output'] ?? null;
                        $ret = $output ? $this->renderTypeName($output) : '()';
                        return "impl $path(" . implode(', ', $inputs) . ") -> $ret";
                    }
                    if ($args && isset($args['angle_bracketed']['args'])) {
                        $types = array_map(fn($a) => $this->renderTypeName($a['type'] ?? null), $args['angle_bracketed']['args']);
                        return "impl $path<" . implode(', ', $types) . ">";
                    }
                    return "impl $path";
                }
            }
        }
        return '?';
    }

    // ---- Enum details ----

    private function renderEnumDetails(array $item, array &$lines): void {
        $enum = $item['inner']['enum'] ?? [];
        $variants = $enum['variants'] ?? [];

        // Variants
        if ($variants) {
            $lines[] = '## Variants';
            $lines[] = '';
            foreach ($variants as $varId) {
                $var = $this->index[(string)$varId] ?? null;
                if (!$var) continue;
                $vname = $var['name'] ?? 'unnamed';
                $vkind = $var['inner']['variant']['kind'] ?? 'plain';
                $vdocs = $this->resolveDocLinks($var['docs'] ?? '', $var['links'] ?? [], 3);

                // Build variant signature
                if (is_array($vkind) && isset($vkind['struct'])) {
                    $fields = [];
                    foreach ($vkind['struct']['fields'] ?? [] as $fid) {
                        $field = $this->index[(string)$fid] ?? null;
                        if ($field) {
                            $fname = $field['name'] ?? '_';
                            $ftype = $this->renderTypeName($field['inner']['struct_field'] ?? null);
                            $fields[] = "$fname: $ftype";
                        }
                    }
                    $sig = $fields ? "$vname { " . implode(', ', $fields) . " }" : "$vname { }";
                } elseif (is_array($vkind) && isset($vkind['tuple'])) {
                    $fields = [];
                    foreach ($vkind['tuple'] as $fid) {
                        $field = $this->index[(string)$fid] ?? null;
                        if ($field) {
                            $ftype = $this->renderTypeName($field['inner']['struct_field'] ?? null);
                            $fields[] = $ftype;
                        }
                    }
                    $sig = "$vname(" . implode(', ', $fields) . ")";
                } else {
                    $sig = $vname;
                }

                $this->appendSignature($lines, $sig);
                $lines[] = '';
                if ($vdocs) { $lines[] = $vdocs; $lines[] = ''; }
            }
        }

        // Impl blocks: separate intrinsic methods from trait impls
        $impls = $enum['impls'] ?? [];
        if (!is_array($impls) || empty($impls)) return;

        $intrinsicMethods = [];
        $traitNames = [];

        foreach ($impls as $implId) {
            $impl = $this->index[(string)$implId] ?? null;
            if (!$impl) continue;
            $implData = $impl['inner']['impl'] ?? [];
            $trait = $implData['trait'] ?? null;
            $implItems = $implData['items'] ?? [];

            if ($trait) {
                $tid = is_array($trait) ? ($trait['id'] ?? null) : $trait;
                if (isset($this->index[(string)$tid])) {
                    $traitItem = $this->index[(string)$tid];
                    $traitNames[] = $traitItem['name'] ?? '?';
                }
            } else {
                foreach ($implItems as $itemId) {
                    $assoc = $this->index[(string)$itemId] ?? null;
                    if ($assoc && ($assoc['visibility'] ?? '') === 'public') {
                        $intrinsicMethods[] = $assoc;
                    }
                }
            }
        }

        if ($intrinsicMethods) {
            $lines[] = '## Implementations';
            $lines[] = '';
            usort($intrinsicMethods, fn($a, $b) => ($a['name'] ?? '') <=> ($b['name'] ?? ''));
            foreach ($intrinsicMethods as $method) {
                $this->renderMethodDetails($method, $lines);
            }
        }

        if ($traitNames) {
            $lines[] = '## Trait Implementations';
            $lines[] = '';
            foreach (array_unique($traitNames) as $tn) {
                $lines[] = "- `$tn`";
            }
            $lines[] = '';
        }
    }

    // ---- Trait details ----

    private function renderTraitDetails(array $item, array &$lines): void {
        $trait = $item['inner']['trait'] ?? [];
        $traitItems = $trait['items'] ?? [];

        if (!$traitItems) return;

        // Separate into four groups: required/provided methods, required/provided associated types
        $requiredMethods = [];
        $providedMethods = [];
        $requiredTypes = [];
        $providedTypes = [];
        $requiredConsts = [];
        $providedConsts = [];
        foreach ($traitItems as $itemId) {
            $assoc = $this->index[(string)$itemId] ?? null;
            if (!$assoc) continue;
            $atype = $this->getItemType($assoc);
            if ($atype === 'function') {
                $hasBody = ($assoc['inner']['function']['has_body'] ?? false);
                if ($hasBody) $providedMethods[] = $assoc;
                else $requiredMethods[] = $assoc;
            } elseif ($atype === 'assoc_type') {
                $hasDefault = ($assoc['inner']['assoc_type']['type'] ?? null) !== null;
                if ($hasDefault) $providedTypes[] = $assoc;
                else $requiredTypes[] = $assoc;
            } elseif ($atype === 'assoc_const') {
                $hasDefault = ($assoc['inner']['assoc_const']['value'] ?? null) !== null;
                if ($hasDefault) $providedConsts[] = $assoc;
                else $requiredConsts[] = $assoc;
            }
        }

        $sections = [
            'Required Methods'             => $requiredMethods,
            'Provided Methods'             => $providedMethods,
            'Required Associated Types'    => $requiredTypes,
            'Provided Associated Types'    => $providedTypes,
            'Required Associated Constants' => $requiredConsts,
            'Provided Associated Constants' => $providedConsts,
        ];
        foreach ($sections as $heading => $items) {
            if (!$items) continue;
            $lines[] = "## $heading";
            $lines[] = '';
            foreach ($items as $assoc) $this->renderTraitItem($assoc, $lines);
        }

        // Implementors
        $implementors = $this->findTraitImplementors((int)$item['id']);
        if ($implementors) {
            $lines[] = '## Implementors';
            $lines[] = '';
            foreach ($implementors as $impl) {
                $link = $this->idToLink((int)$impl['id']);
                $implName = $impl['name'] ?? 'unnamed';
                $lines[] = "- [$implName]($link)";
            }
            $lines[] = '';
        }
    }

    private function renderTraitItem(array $assoc, array &$lines): void {
        $atype = $this->getItemType($assoc);
        $aname = $assoc['name'] ?? 'unnamed';
        $docs = $this->resolveDocLinks($assoc['docs'] ?? '', $assoc['links'] ?? [], 3);

        if ($atype === 'function') {
            $this->renderMethodDetails($assoc, $lines);
        } else {
            $prefix = match ($atype) {
                'assoc_const' => 'const',
                'assoc_type'  => 'type',
                default       => '?',
            };
            $lines[] = "### `$prefix $aname`";
            $lines[] = '';
            if ($docs) { $lines[] = $docs; $lines[] = ''; }
        }
    }

    /**
     * Find all public types that implement a given trait.
     */
    private function findTraitImplementors(int $traitId): array {
        $implementors = [];
        foreach ($this->index as $id => $item) {
            $type = $this->getItemType($item);
            if (!in_array($type, ['struct', 'enum'], true)) continue;
            if (!$this->isPublic($item)) continue;

            $impls = ($type === 'struct')
                ? ($item['inner']['struct']['impls'] ?? [])
                : ($item['inner']['enum']['impls'] ?? []);
            if (!is_array($impls)) continue;

            foreach ($impls as $implId) {
                $impl = $this->index[(string)$implId] ?? null;
                if (!$impl) continue;
                $implData = $impl['inner']['impl'] ?? [];
                $trait = $implData['trait'] ?? null;
                if ($trait) {
                    $tid = is_array($trait) ? ($trait['id'] ?? null) : $trait;
                    if ((int)$tid === $traitId) {
                        $implementors[] = $item;
                        break;
                    }
                }
            }
        }
        usort($implementors, fn($a, $b) => ($a['name'] ?? '') <=> ($b['name'] ?? ''));
        return $implementors;
    }

    // ---- Function details ----

    private function renderFunctionDetails(array $item, array &$lines): void {
        $func = $item['inner']['function'] ?? [];
        $sig = $func['sig'] ?? [];

        // Parameters
        $params = [];
        foreach ($sig['inputs'] ?? [] as $input) {
            if (isset($input[0]) && $input[0] === 'self') continue;
            $pname = $input[0] ?? '_';
            $ptype = $this->renderTypeName($input[1] ?? null);
            $params[] = "$pname: $ptype";
        }

        // Return type
        $ret = $this->renderTypeName($sig['output'] ?? null);

        // Generics
        $generics = $item['generics'] ?? null;
        if (!$generics) $generics = ($func['generics'] ?? null);
        $genParams = $this->getGenericParamNames($generics);

        // Where predicates
        $whereLines = $this->getWhereLines($generics);

        // Build signature line
        $name = $item['name'] ?? 'unnamed';
        $sigLine = "fn $name(" . implode(', ', $params) . ")";
        if ($genParams) $sigLine = "fn $name<" . implode(', ', $genParams) . ">(" . implode(', ', $params) . ")";
        if ($ret !== '()') $sigLine .= " -> $ret";

        $lines[] = '```rust';
        $lines[] = $sigLine;
        if ($whereLines) {
            $lines[] = 'where';
            foreach ($whereLines as $w) $lines[] = $w;
        }
        $lines[] = '```';
        $lines[] = '';
    }


}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

function main(): void {
    // Parse command-line options
    $quoteDescriptions = true;
    $signatureBlocks = true;
    $outputDir = null;
    $args = $_SERVER['argv'] ?? [];
    $i = 1; // skip script name
    while ($i < count($args)) {
        switch ($args[$i]) {
            case '--help':
                echo "Usage: doc-md.php [options]\n\n";
                echo "Options:\n";
                echo "  --help                        Show this help message\n";
                echo "  --minimal                     Enable all --no-* options\n";
                echo "  --no-quote-descriptions       Do not wrap descriptions in block quotes\n";
                echo "  --no-signature-blocks         Render signatures inline instead of code blocks\n";
                echo "  --output <path>               Output directory (default: target/doc-md/)\n";
                exit(0);
            case '--output':
                $i++;
                if ($i >= count($args)) {
                    fwrite(STDERR, "Error: --output requires a path argument\n");
                    exit(1);
                }
                $outputDir = $args[$i];
                break;
            case '--minimal':
                $quoteDescriptions = false;
                $signatureBlocks = false;
                break;
            case '--no-quote-descriptions':
                $quoteDescriptions = false;
                break;
            case '--no-signature-blocks':
                $signatureBlocks = false;
                break;
            default:
                fwrite(STDERR, "Error: unknown option '{$args[$i]}'\n");
                exit(1);
        }
        $i++;
    }

    $rootDir = getcwd();
    $targetDir = $rootDir . '/target';

    // Detect crate name from Cargo.toml
    $crateName = 'crate';
    $cargoToml = $rootDir . '/Cargo.toml';

    if (!file_exists($cargoToml)) {
        fwrite(STDERR, "Error: cargo toml file found at $cargoToml\n");
        exit(1);
    }

    $lines = file($cargoToml);
    foreach ($lines as $line) {
        if (preg_match('/^name\s*=\s*"([^"]+)"/', $line, $m)) {
            $crateName = $m[1];
            break;
        }
    }

    $jsonFile = $targetDir . '/doc/' . $crateName . '.json';
    $outDir = $outputDir ?? ($targetDir . '/doc-md');

    ensure_nightly();

    echo "Generating rustdoc JSON... ";
    cmd("cd $rootDir && rustup run nightly cargo doc -Z unstable-options --output-format=json --no-deps 2>&1");
    echo "Done.\n";

    if (!file_exists($jsonFile)) {
        fwrite(STDERR, "Error: rustdoc JSON not found at $jsonFile\n");
        exit(1);
    }

    $json = file_get_contents($jsonFile);
    $data = json_decode($json, true);
    if (!$data) {
        fwrite(STDERR, "Error: failed to parse rustdoc JSON\n");
        exit(1);
    }

    if (is_dir($outDir)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outDir));
        foreach ($files as $file) {
            if ($file->isFile() && pathinfo($file->getPathname(), PATHINFO_EXTENSION) !== 'md') {
                fwrite(STDERR, "Error: output directory '$outDir' contains non-.md entries. Aborting to prevent data loss.\n");
                exit(1);
            }
        }
        exec("rm -rf " . escapeshellarg($outDir));
    }
    mkdir($outDir, 0755, true);

    echo "Generating markdown documentation... ";
    $gen = new DocGenerator($data, $outDir, $crateName, $quoteDescriptions, $signatureBlocks);
    $gen->generate();
    echo "Done.\nOutput in: {$outDir}/\n";
}

main();

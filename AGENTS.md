# AGENTS.md

## What this is

`doc-md.php` generates Markdown documentation from rustdoc JSON output. It takes a Rust crate's rustdoc JSON (produced by `cargo doc --json`) and outputs a tree of `.md` files mirroring the HTML docs structure, with relative cross-references, summary tables, and detailed item pages.

## Usage

```bash
# From a Rust crate root (e.g. /path/to/my-crate/)
php /path/to/rustdoc-md/doc-md.php

# Output goes to target/doc-md/
```

The script auto-detects the crate name from `Cargo.toml`, installs nightly Rust if needed, generates rustdoc JSON, then produces the Markdown output.

## Architecture

Single PHP file, ~1000 lines. Main class `DocGenerator` with a two-pass approach:

### Pass 1: Registration (`registerModulePaths`)
Walks all modules recursively via `expandModuleChildren()` (follows glob re-exports), registering each public item at its shortest/most-public path in `$itemPaths` (id -> path mapping).

### Pass 2: Generation (`generate`)
- `generateCrateIndex` — crate root index page
- `generateModule` — module index pages with summary tables
- `generateItemPage` — standalone detail pages for each public item

## Key Methods

| Method | Purpose |
|--------|---------|
| `expandModuleChildren()` | Follows `pub use module::*` re-exports to resolve items |
| `registerItem()` | Maps item ID to its public path (shortest path wins) |
| `relativePath()` | Computes relative links between pages |
| `resolveDocLinks()` | Resolves doc cross-references, filters `#` boilerplate |
| `renderTypeName()` | Converts rustdoc type JSON to readable string (handles generics, refs, arrays, impl_trait) |
| `renderSignature()` | Renders item title with generics (filters synthetic params) |
| `renderStructDetails()` | Fields, impl-block-grouped methods with headers, trait list |
| `renderMethodDetails()` | Full method signature with params, return type, where clause |
| `renderImplHeader()` | Shows `impl<T> Name where T: Trait` code blocks |

## Rustdoc JSON Structure

- Top-level: `{ root: int, crate_version: string, index: dict }`
- Index: `{ id: int, name: string, visibility: string, inner: { type: { ... } } }`
- Modules: `inner.module.items` = array of item IDs
- Structs: `inner.struct.impls` = array of impl block IDs, `inner.struct.kind.plain.fields` = field IDs
- Impls: `inner.impl.trait` (null = intrinsic), `inner.impl.items` = method IDs, `inner.impl.generics` = generics + where predicates
- Functions: `inner.function.sig.inputs` / `.output`, `inner.function.generics`
- Types: nested dicts with keys like `primitive`, `generic`, `resolved_path`, `borrowed_ref`, `tuple`, `array`, `impl_trait`, etc.

## Design Decisions

- **Shortest path wins**: items re-exported in multiple modules register at the most public location
- **First-generation wins**: item pages generated only once (tracked via `$generatedItems`)
- **Table filtering**: module tables only show items registered in that module or its ancestors/children
- **Synthetic generics filtered**: `impl FnMut(...)` trait bounds hidden from titles
- **Boilerplate hidden**: `#` lines in code blocks stripped from docs
- **Public paths for types**: `$itemPaths` used to resolve types to their public crate paths

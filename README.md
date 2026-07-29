# rustdoc-md

## What this is

`doc-md.php` generates Markdown documentation for Rust crates and outputs a tree of `.md` files mirroring the HTML docs structure, with relative cross-references, summary tables, and detailed item pages.

Current implementation is a working prototype written in `PHP`.

## Usage

```bash
cd /path/to/your-crate
/path/to/rustdoc-md/doc-md.php
```

Generated documentation is written to `target/doc-md/`
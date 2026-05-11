# GML AI SEO — Tests

Self-contained smoke / integration tests. No WordPress, no PHPUnit required — each test file boots a minimal mock of the WP primitives it needs (see `bootstrap-mock.php`) and exits non-zero on the first broken assertion.

## Why they exist

Developer regression net. Run them before cutting a release or after any change to:

- `class-migration-manager.php`
- `class-conflict-detector.php`
- `class-gradual-mode-manager.php`
- Any adapter in `includes/migration/`

The integration test exercises the design §8.3 end-to-end flow (Yoast fixtures → conflict detection → scan → start → cron batch → gradual mode → adopt suggestion → exit), and the regression test pins v1.8.0-equivalent behaviour when no competing plugin is active.

## Why they're not in the release zip

`tests/` is excluded from `gml-seo-vX.Y.Z.zip` because WordPress doesn't need them at runtime and they'd only add dead weight. The packaging command is:

```bash
zip -r gml-seo-vX.Y.Z.zip gml-seo \
  --exclude "gml-seo/.git/*" \
  --exclude "gml-seo/.gitignore" \
  --exclude "gml-seo/tests/*" \
  --exclude "gml-seo/.DS_Store" \
  --exclude "gml-seo/Thumbs.db"
```

They stay in the Git repo so collaborators (and future-you) can run them.

## How to run

From the repo root:

```bash
php plugins/gml-seo/tests/integration/test-full-migration-flow.php
php plugins/gml-seo/tests/integration/test-v1.8.0-regression.php
```

Each prints `OK <name>` on success and exits 0, or prints `FAIL: ...` and exits 1 on the first broken assertion.

## Layout

```
tests/
├── README.md                                    (this file)
├── bootstrap-mock.php                           (shared WP mock)
└── integration/
    ├── test-full-migration-flow.php             (end-to-end, design §8.3)
    └── test-v1.8.0-regression.php               (pins legacy behaviour)
```

## Adding a new test

1. Put it under `tests/integration/` or a new subfolder (e.g. `tests/unit/`).
2. `require_once __DIR__ . '/../bootstrap-mock.php';` first, then `require_once` whatever class you're exercising.
3. Reset mock state with `GML_SEO_Mock::reset();` at the top of each scenario.
4. Use a simple assertion helper (`iassert` / `rassert` in existing files) that exits non-zero on failure — avoids pulling in PHPUnit.
5. End with `echo "OK <name>\n";` so `&&`-chaining works in scripts.

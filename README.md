# Glossary Tooltip

Highlights glossary terms in content and shows their description in a tooltip.

## Running tests

The module has two test suites in `tests/src`:

| Suite | What it covers |
|---|---|
| `Kernel/GlossaryTooltipFilterTest` | Term matching (longest first, whole words, case, non-Latin scripts), skipped links/code, unpublished terms, 100-character description and "Read more", markup and ARIA attributes, first/every occurrence, cache tags |
| `Functional/GlossaryTooltipTest` | Real pages with page cache on: term edits/unpublishing show up for anonymous users without a cache clear, settings form and access, "Read more" link, filter enabled on install |

### Requirements

- A Drupal 11 project with dev dependencies: `composer require --dev drupal/core-dev`
- Environment variables:
  - `SIMPLETEST_DB`: database for test sites, e.g. `sqlite://localhost//tmp/test.sqlite`
  - `SIMPLETEST_BASE_URL`: URL of the site as seen by the test runner (functional tests only)
  - `BROWSERTEST_OUTPUT_DIRECTORY`: writable directory for HTML output of functional tests (optional)

### With DDEV

From the project root:

```bash
ddev exec "SIMPLETEST_DB=sqlite://localhost//tmp/test.sqlite \
  SIMPLETEST_BASE_URL=http://web \
  vendor/bin/phpunit -c web/core --testdox web/modules/custom/glossary_tooltip"
```

Run one suite or one test:

```bash
# Kernel tests only (fast, no web server needed).
ddev exec "SIMPLETEST_DB=sqlite://localhost//tmp/test.sqlite \
  vendor/bin/phpunit -c web/core web/modules/custom/glossary_tooltip/tests/src/Kernel"

# A single test method.
ddev exec "SIMPLETEST_DB=sqlite://localhost//tmp/test.sqlite \
  vendor/bin/phpunit -c web/core --filter testMatching web/modules/custom/glossary_tooltip"
```

### Without DDEV

Same command from the project root, with the variables pointing to your
environment, e.g. `SIMPLETEST_BASE_URL=http://localhost:8080`.

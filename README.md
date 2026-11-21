# Glossary Tooltip

Highlights glossary terms in content and shows their description in a
tooltip, with a "Read more" link to the term page.

Requires Drupal 11 and the core Taxonomy and Filter modules.

## Installation

1. Place the module in `web/modules/custom/glossary_tooltip`.
2. Enable it: `drush en glossary_tooltip`.

On install the module:

- creates the **Glossary** vocabulary;
- enables the **Glossary tooltips** filter in the *Basic HTML* and
  *Full HTML* text formats, if they exist. For other formats, enable it at
  *Configuration > Content authoring > Text formats and editors*. It must
  run after "Limit allowed HTML tags", or the tooltip markup is stripped.

## Usage

1. Add terms at *Structure > Taxonomy > Glossary*. The **Name** is the word
   or phrase to highlight; the **Description** is the tooltip text.
2. Matching terms in any text field that uses a format with the filter
   are highlighted. Only published terms are used.

How matching works:

- whole words only, case-insensitive ("Food" does not match "Seafood");
- the longest term wins ("Wet food" over "Food");
- works with non-Latin scripts, including languages without spaces
  (Chinese, Japanese, Thai);
- text inside links, `<code>`, `<pre>`, `<button>` is left unchanged.

The tooltip shows up to 100 characters of the description. If it is longer,
a "Read more" link to the term page is added. It opens on hover, keyboard
focus or tap; Escape or a tap elsewhere closes it.

Term changes (edit, unpublish, delete, new term) appear on the next page
load without clearing caches.

## Settings

*Configuration > Content authoring > Glossary Tooltip*
(`/admin/config/content/glossary-tooltip`, permission
*Administer Glossary Tooltip*):

- **Highlight every occurrence** (default) or **only the first
  occurrence** of a term. "First" applies to each text field separately.

## Theming

- Template: `templates/glossary-tooltip.html.twig`, can be overridden in
  a theme.
- Colours and width are CSS custom properties on `.glossary-tooltip`,
  e.g. `--glossary-tooltip-bg`, `--glossary-tooltip-accent`,
  `--glossary-tooltip-width`.
- Fonts (Roboto, Roboto Slab) load from Google Fonts via the
  `glossary_tooltip/fonts` library. To use the theme's fonts instead,
  add this to the theme's `.info.yml`:

  ```yaml
  libraries-override:
    glossary_tooltip/fonts: false
  ```

The open animation follows the design reference (jQuery UI tooltip,
"open event": fade in and slide 10px). It is done in CSS, because jQuery UI
is no longer in Drupal core.

## Known limitations

- A term split by markup (`wet <strong>food</strong>`) is matched only
  within one text node.
- In languages without spaces a short term can match inside a longer word.
- The tooltip can be clipped by a parent element with `overflow: hidden`.
- Changes appear on the next page load, not live on open pages.

## Running tests

Kernel tests cover matching, markup and settings; functional tests cover
real pages with page cache, the settings form and install.

```bash
composer require --dev drupal/core-dev   # once

# From the project root (with DDEV, wrap in: ddev exec "...").
SIMPLETEST_DB=sqlite://localhost//tmp/test.sqlite \
SIMPLETEST_BASE_URL=http://web \
vendor/bin/phpunit -c web/core --testdox web/modules/custom/glossary_tooltip
```

- `SIMPLETEST_BASE_URL` is the site URL as the test runner sees it
  (`http://web` inside DDEV); only functional tests need it.
- Only kernel tests: add `/tests/src/Kernel` to the path.
- One test: add `--filter testMatching`.

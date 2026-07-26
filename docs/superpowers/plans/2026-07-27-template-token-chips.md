# Template Token Chips Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Render each `%%token%%` in a template field as an inline chip showing its human label, while the value submitted and stored stays exactly the text it is today.

**Architecture:** The server keeps rendering the real `<input>`. JavaScript sets its `hidden` attribute — hidden inputs still submit — mounts a React root beside it, and writes the canonical `%%token%%` text back into that input on every change. So the submitted field never changes, no PHP change is required by the surface itself, and with JavaScript off the plain input still works.

**Tech Stack:** PHP 8.3, WordPress 7.0 (core-bundled `@wordpress/element`, `@wordpress/rich-text`, `@wordpress/components`, `@wordpress/block-editor`), `@wordpress/scripts` 30 for build and Jest, Playwright.

**Spec:** `docs/superpowers/specs/2026-07-26-template-token-chips-design.md`

## Global Constraints

- **Established WordPress components only.** Everything comes from core-bundled packages — verified present in WP 7.0's `wp-includes/js/dist/`: `rich-text` exports `create`, `toHTMLString`, `registerFormatType`, `insertObject`; `block-editor` exports `BlockEditorProvider`; `components` and `element` are registered handles. **No third-party runtime dependency, no stylesheet, no custom CSS class.** Chips reuse core's `button button-small` classes on a `<span>`.
- **The variable list is serialised exactly once.** JavaScript learns slugs and labels from the rendered pills' `data-taseo-template-var` / `data-taseo-template-label` attributes. **Do NOT add `wp_localize_script` or an inline JSON copy** — a second serialisation is the drift this whole line of work exists to remove.
- **The stored value format does not change.** `%%title%% %%sep%% %%sitename%% — Shop` in, the same string out. This is what makes the feature reversible.
- **Round-tripping must be exact, including case.** A stored `%%TITLE%%` must come back as `%%TITLE%%`, not `%%title%%`. Opening the tab must never rewrite an admin's stored template.
- **Unknown tokens survive.** A token whose slug is not in the registry renders as a chip marked unknown and round-trips unchanged. Dropping it would silently edit stored data.
- PHP 8.3, tabs, `array()` long syntax, Yoda conditions, no closing `?>`, `@param`/`@return`, text domain `the-another-seo`, output escaped.
- JavaScript follows the repo's existing style: tabs, spaces inside parens and brackets, single quotes, JSX as used in `blocks/breadcrumbs/index.js`.
- **No version bump.**
- **Run tests:** `make test` (PHP, Docker). `make lint`. `make test-e2e` — **FOREGROUND only**, Bash `timeout` `900000` ms, `docker ps` checked first. Backgrounding it has stalled agents repeatedly on this branch.
- **Baselines:** `make test` → `OK (325 tests, 950 assertions)`. `make test-e2e` → 29 passed.
- Conventional Commits, imperative mood. Branch `feature/webmaster-verification` (PR #2 open).

---

## File Structure

**Created:**

| Path | Responsibility |
|---|---|
| `assets/src/settings/template-value.js` | Pure parse/serialise between `%%token%%` text and segment arrays. All the correctness risk lives here. |
| `assets/src/settings/template-value.test.js` | Jest round-trip coverage. |
| `assets/src/settings/index.js` | Build entry: finds each template input, hides it, mounts the editor. |
| `assets/src/settings/TemplateField.js` | The React component: chips, autocomplete, writing back to the input. |

**Modified:**

| Path | Change |
|---|---|
| `includes/Admin/SettingsPage.php` | Pills gain `data-taseo-template-label`; enqueue reads the built `.asset.php`. |
| `package.json` | Second build entry; `test:unit:js` script. |
| `Makefile` | `test-js` target. |
| `.github/workflows/ci.yml` | A fifth job running the JS unit tests. |
| `tests/Unit/Admin/SettingsPageTest.php` | Label attribute; enqueue assertions. |
| `tests/e2e/functional/specs/webmaster-admin.spec.ts`, `specs/zz-admin-tour.spec.ts` | Rewritten to drive the new surface. |

**Deleted:** `assets/js/settings.js` — replaced wholesale by the built bundle.

---

### Task 1: Pills carry their human label

**Files:**
- Modify: `includes/Admin/SettingsPage.php` (`render_variable_pills()`)
- Test: `tests/Unit/Admin/SettingsPageTest.php`

**Interfaces:**
- Produces: each pill renders `data-taseo-template-var="%%slug%%"` (existing) **and** `data-taseo-template-label="Human Label"` (new). This pair is the only channel by which JavaScript learns the variable list — Task 3 reads it.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Admin/SettingsPageTest.php`. Set the tab first — `render_page()` unsets `$_GET['tab']` after rendering but never sets it, so without this line the General tab renders:

```php
	public function test_variable_pills_carry_both_the_token_and_its_human_label(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page();

		$this->assertStringContainsString( 'data-taseo-template-var="%%title%%"', $html );
		$this->assertStringContainsString( 'data-taseo-template-label="', $html );
		$this->assertMatchesRegularExpression(
			'/data-taseo-template-var="%%title%%"\s+data-taseo-template-label="[^"]+"/',
			$html,
			'each pill must carry its label alongside its token'
		);
	}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `make test`
Expected: FAIL — the label attribute does not exist.

- [ ] **Step 3: Add the attribute**

In `render_variable_pills()`, iterate the registry's key/value pairs instead of only its keys, and emit both attributes:

```php
		foreach ( $this->template_variables->get_for( $object_type, $object_subtype ) as $slug => $label ) {
			$token = '%%' . $slug . '%%';

			printf(
				'<button type="button" class="button button-small" data-taseo-template-var="%1$s" data-taseo-template-label="%2$s">%1$s</button> ',
				esc_attr( $token ),
				esc_attr( $label )
			);
		}
```

The visible pill text stays the token, so the tab reads the same as today. The label rides along for JavaScript to use on the chips.

- [ ] **Step 4: Run the tests, lint, commit**

```bash
make test
make lint
git add includes/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: carry each variable's human label on its pill"
```

Expected: green, above 325 tests.

---

### Task 2: Jest harness and the value converter

**Files:**
- Create: `assets/src/settings/template-value.js`, `assets/src/settings/template-value.test.js`
- Modify: `package.json`, `Makefile`, `.github/workflows/ci.yml`

**Interfaces:**
- Produces:
  - `parseTemplate( template: string ): Segment[]` where `Segment` is `{ type: 'text', value: string }` or `{ type: 'token', slug: string, raw: string }`. `slug` is lowercased for lookups; `raw` preserves the token exactly as written.
  - `serializeSegments( segments: Segment[] ): string`
  - The property `serializeSegments( parseTemplate( x ) ) === x` for every `x`.

**Why `raw` exists, and why it is not redundant.** Validation and the registry are case-insensitive, so `%%TITLE%%` is a legitimate stored value. If a segment kept only the lowercased slug, re-serialising would rewrite it to `%%title%%` — silently editing an admin's stored template merely because they opened the tab. `raw` makes the round trip exact; `slug` is used only to look a label up.

- [ ] **Step 1: Wire the harness**

In `package.json`, add to `scripts`:

```json
		"test:unit:js": "wp-scripts test-unit-js --config '{\"testMatch\":[\"**/assets/src/**/*.test.js\"]}'",
```

In `Makefile`, alongside the other suite targets:

```makefile
# Run the JavaScript unit tests (Jest via @wordpress/scripts, in Docker).
test-js: docker-build
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run test:unit:js"
```

Add `test-js` to the `.PHONY` list at the top of the Makefile.

In `.github/workflows/ci.yml`, add a fifth job mirroring the shape of the existing PHPUnit job — checkout, set up the toolchain, run the suite:

```yaml
  test-js:
    name: JS Unit
    runs-on: ubuntu-24.04
    steps:
      - name: Checkout code
        uses: actions/checkout@34e114876b0b11c390a56381ad16ebd13914f8d5  # v4

      - name: Set up toolchain
        run: sh scripts/setup/unit.sh

      - name: Run Jest
        run: npm ci && npm run test:unit:js
```

Copy the `uses:` SHA from a sibling job in the same file rather than typing it — the repo pins actions by SHA and a typo there fails opaquely.

- [ ] **Step 2: Write the failing tests**

Create `assets/src/settings/template-value.test.js`:

```js
/**
 * The parse/serialise pair is where every correctness risk in the chips
 * feature lives: it stands between what an administrator stored and what
 * the editing surface shows. These tests pin the round trip.
 */

import { parseTemplate, serializeSegments } from './template-value';

describe( 'parseTemplate', () => {
	it( 'splits text and tokens in order', () => {
		expect( parseTemplate( '%%title%% — Shop' ) ).toEqual( [
			{ type: 'token', slug: 'title', raw: '%%title%%' },
			{ type: 'text', value: ' — Shop' },
		] );
	} );

	it( 'keeps literal text between adjacent tokens', () => {
		expect( parseTemplate( '%%title%% %%sep%% %%sitename%%' ) ).toHaveLength( 5 );
	} );

	it( 'handles adjacent tokens with nothing between them', () => {
		expect( parseTemplate( '%%title%%%%sep%%' ) ).toEqual( [
			{ type: 'token', slug: 'title', raw: '%%title%%' },
			{ type: 'token', slug: 'sep', raw: '%%sep%%' },
		] );
	} );

	it( 'treats an unpaired delimiter as literal text', () => {
		expect( parseTemplate( '%%oops and %%title%%' ) ).toEqual( [
			{ type: 'text', value: '%%oops and ' },
			{ type: 'token', slug: 'title', raw: '%%title%%' },
		] );
	} );

	it( 'lowercases the slug but preserves the original token', () => {
		expect( parseTemplate( '%%TITLE%%' ) ).toEqual( [
			{ type: 'token', slug: 'title', raw: '%%TITLE%%' },
		] );
	} );

	it( 'returns a single text segment when there are no tokens', () => {
		expect( parseTemplate( 'Just a static title' ) ).toEqual( [
			{ type: 'text', value: 'Just a static title' },
		] );
	} );

	it( 'returns nothing for an empty template', () => {
		expect( parseTemplate( '' ) ).toEqual( [] );
	} );
} );

describe( 'round trip', () => {
	it.each( [
		'%%title%% %%sep%% %%sitename%%',
		'%%title%% — Shop',
		'%%title%%%%sep%%',
		'%%oops and %%title%%',
		'%%TITLE%% %%Sep%%',
		'%%not_a_registered_variable%%',
		'Just a static title',
		'',
		'  leading and trailing  ',
	] )( 'serializeSegments( parseTemplate( %j ) ) returns the input unchanged', ( template ) => {
		expect( serializeSegments( parseTemplate( template ) ) ).toBe( template );
	} );
} );
```

- [ ] **Step 3: Run them to verify they fail**

Run: `make test-js`
Expected: FAIL — the module does not exist.

- [ ] **Step 4: Implement**

Create `assets/src/settings/template-value.js`:

```js
/**
 * Convert between a stored template string and an ordered list of segments.
 *
 * The pattern matches PHP's TemplateResolver::TOKEN_PATTERN — that constant
 * is the single definition of what a token looks like on the server, and
 * this is its client-side mirror. If one changes, so must the other, or a
 * template could validate one way and render another.
 */
const TOKEN_PATTERN = /%%([a-z0-9_]+)%%/gi;

/**
 * Split a template into text and token segments, in order.
 *
 * Each token segment keeps both a lowercased `slug`, for looking a label
 * up, and the `raw` token exactly as written. The raw form is what makes
 * the round trip exact: %%TITLE%% is a legitimate stored value, and
 * re-serialising from the slug alone would rewrite it to %%title%% —
 * silently editing what an administrator stored, just because they opened
 * the tab.
 *
 * @param {string} template Stored template.
 * @return {Array<Object>} Segments.
 */
export function parseTemplate( template ) {
	const segments = [];
	let lastIndex = 0;
	let match;

	TOKEN_PATTERN.lastIndex = 0;

	while ( ( match = TOKEN_PATTERN.exec( template ) ) !== null ) {
		if ( match.index > lastIndex ) {
			segments.push( {
				type: 'text',
				value: template.slice( lastIndex, match.index ),
			} );
		}

		segments.push( {
			type: 'token',
			slug: match[ 1 ].toLowerCase(),
			raw: match[ 0 ],
		} );

		lastIndex = TOKEN_PATTERN.lastIndex;
	}

	if ( lastIndex < template.length ) {
		segments.push( { type: 'text', value: template.slice( lastIndex ) } );
	}

	return segments;
}

/**
 * Rebuild the stored template string from its segments.
 *
 * @param {Array<Object>} segments Segments.
 * @return {string} Template.
 */
export function serializeSegments( segments ) {
	return segments
		.map( ( segment ) =>
			'token' === segment.type ? segment.raw : segment.value
		)
		.join( '' );
}
```

`TOKEN_PATTERN.lastIndex = 0` before the loop is load-bearing: the regex carries the `g` flag, so it is stateful across calls, and without the reset a second call on a shorter string starts mid-way and silently loses leading tokens.

- [ ] **Step 5: Run the tests, then the whole gate, then commit**

```bash
make test-js
make test
make lint
git add assets/src package.json Makefile .github/workflows/ci.yml
git commit -m "test: add a JS unit harness and the template value converter"
```

Expected: Jest green, PHP suite unchanged at 325.

---

### Task 3: The editing surface

**Files:**
- Create: `assets/src/settings/index.js`, `assets/src/settings/TemplateField.js`
- Delete: `assets/js/settings.js`
- Modify: `includes/Admin/SettingsPage.php` (enqueue), `package.json` (build entry), `tests/Unit/Admin/SettingsPageTest.php`, `tests/e2e/functional/specs/webmaster-admin.spec.ts`, `tests/e2e/functional/specs/zz-admin-tour.spec.ts`

**Interfaces:**
- Consumes: `parseTemplate`/`serializeSegments` (Task 2); the pills' `data-taseo-template-var` and `data-taseo-template-label` (Task 1); the inputs' `data-taseo-template-input` marker (already present).

**This task is atomic by nature and cannot be split.** Hiding the input, mounting the surface, porting the autocomplete, and retargeting the e2e specs are one change: the moment the input is hidden, `fill()` throws in the specs, and the moment the old bundle is deleted the autocomplete disappears. Any smaller slice leaves the suite red.

- [ ] **Step 1: Spike the editor surface before building on it**

**This is the one genuine unknown in the plan.** `RichText` from `@wordpress/block-editor` is designed to run inside the block editor. It is used successfully in standalone contexts wrapped in a minimal `BlockEditorProvider`, but that is not something this plan can assert without running it.

Build a throwaway entry that renders a single `RichText` inside `<BlockEditorProvider value={ [] } onChange={ () => {} }>` on the settings page, type into it, and confirm it renders and fires `onChange`.

- **If it works:** proceed with `RichText` for the rest of this task.
- **If it does not:** fall back to a `contenteditable` div whose value is managed with `@wordpress/rich-text`'s `create()`/`toHTMLString()` — still core's library for the model, with the DOM binding owned locally. Record in your report which route you took, what you observed, and why.

Delete the spike before continuing. Do not commit it. Report the outcome either way — the next task's reviewer needs to know which surface it is reviewing.

- [ ] **Step 2: Register the chip format**

In `assets/src/settings/TemplateField.js`, register a format type for the chip. `object: true` makes it void and atomic — the caret treats a chip as one unit and it cannot be edited character by character, which is exactly the behaviour a token needs:

```js
import { registerFormatType } from '@wordpress/rich-text';

export const CHIP_FORMAT = 'taseo/template-variable';

registerFormatType( CHIP_FORMAT, {
	title: 'Template variable',
	tagName: 'span',
	className: 'button button-small',
	object: true,
	attributes: {
		token: 'data-taseo-token',
		unknown: 'data-taseo-unknown',
	},
	edit: () => null,
} );
```

The `button button-small` class is core's own button styling applied to a `<span>` — it produces the pill appearance with no stylesheet, and matches the clickable pills below the field.

- [ ] **Step 3: Build the field component**

`TemplateField.js` renders the surface for one input. It reads the row's pills for its slug→label map, converts the input's value into a rich-text value with chips, and writes the canonical text back on every change:

```js
import { useState } from '@wordpress/element';
import { create, insertObject, toHTMLString } from '@wordpress/rich-text';
import { parseTemplate, serializeSegments } from './template-value';

/**
 * Slug => human label for one row, read from that row's rendered pills.
 *
 * The pills are the only serialisation of the registry on the page; there
 * is deliberately no wp_localize_script copy, because a second copy can
 * drift from what the pills show.
 *
 * @param {Element} input The template input.
 * @return {Object} Slug => label.
 */
export function rowLabels( input ) {
	const row = input.closest( 'tr' );
	const labels = {};

	if ( ! row ) {
		return labels;
	}

	row.querySelectorAll( '[data-taseo-template-var]' ).forEach( ( pill ) => {
		const token = pill.getAttribute( 'data-taseo-template-var' );
		const slug = token.slice( 2, -2 ).toLowerCase();

		labels[ slug ] = pill.getAttribute( 'data-taseo-template-label' ) || slug;
	} );

	return labels;
}
```

The component itself holds the segment list in state, renders each token segment as a chip labelled `labels[ segment.slug ]` — falling back to the raw slug and adding core's `form-invalid` class when the slug is absent from `labels`, which is what makes an unresolvable variable visibly different — and calls `serializeSegments()` into the hidden input on every change.

- [ ] **Step 4: Mount on every template input**

`assets/src/settings/index.js`:

```js
import { createRoot } from '@wordpress/element';
import TemplateField from './TemplateField';

document.addEventListener( 'DOMContentLoaded', () => {
	document
		.querySelectorAll( '[data-taseo-template-input]' )
		.forEach( ( input ) => {
			const mount = document.createElement( 'div' );

			input.parentNode.insertBefore( mount, input.nextSibling );

			// Hidden inputs still submit, so the server-rendered field stays
			// the one source of truth and the surface is a view over it.
			// With this script absent the input is simply visible and
			// editable, which is the whole degradation story.
			input.hidden = true;

			createRoot( mount ).render( <TemplateField input={ input } /> );
		} );
} );
```

- [ ] **Step 5: Add the build entry and switch the enqueue**

In `package.json`, chain a second build after the block's:

```json
		"build": "wp-scripts build blocks/breadcrumbs/index.js --output-path=dist/breadcrumbs && wp-scripts build assets/src/settings/index.js --output-path=dist/settings",
```

Extend `lint:js` to cover the new source: `"lint:js": "wp-scripts lint-js blocks assets/src"`.

In `SettingsPage::enqueue_assets()`, read the generated asset file for dependencies and version, which is how core tracks what a built bundle actually imports. `dist/` is deliberately **not** excluded by `.distignore`, so the built file ships:

```php
		$asset_file = THE_ANOTHER_SEO_PLUGIN_DIR . 'dist/settings/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'taseo-settings',
			THE_ANOTHER_SEO_PLUGIN_URL . 'dist/settings/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
```

The `file_exists()` guard matters: a source checkout that has not been built would otherwise fatal on `require`, and this file runs in `wp-admin`.

Delete `assets/js/settings.js`. Update the enqueue test that asserted a `jquery-ui-autocomplete` dependency — that dependency is gone, and the test should now assert the handle is enqueued from the built path.

- [ ] **Step 6: Port the autocomplete**

Wrap the surface in `@wordpress/components`' `Autocomplete` with a completer triggered on `%%`, whose `options` are the row's variables from `rowLabels()`, and whose `getOptionCompletion` inserts a chip via `insertObject`. Behaviour is unchanged from the jQuery version: same trigger, same per-row list.

- [ ] **Step 7: Retarget the e2e specs**

Both `webmaster-admin.spec.ts` and `zz-admin-tour.spec.ts` call `fill()` on `input[name="…"]`, which now throws because the input is hidden. Replace those with typing into the surface, and read back the hidden input's `value` where a spec asserts on the stored text.

**This is expected churn, not breakage** — the editing surface is what changed. The previous plan's rule that these specs must pass unmodified was correct for a labels-and-headings change and does not apply here.

Add the assertions the spec calls for: a stored template renders as chips showing human labels; typing `%%pri` offers the row's matching variables and selecting one inserts a chip; the value submitted after editing through the surface is the correct `%%token%%` text.

- [ ] **Step 8: Prove the degradation path**

Add an e2e assertion that with the script blocked — Playwright's `page.route()` aborting the bundle's URL — the plain input is visible, editable, and saves.

**This is the most important assertion in the task.** Everything else verifies the new surface works; this one verifies that when it does not load, the feature is still usable. It is the assertion that makes "degrades gracefully" a fact rather than a claim.

- [ ] **Step 9: Verify and commit**

```bash
npm run build
make test-js
make test
make lint
make test-e2e   # FOREGROUND, timeout 900000, docker ps checked first
make check-plugin
```

All green. The e2e count will have grown; report the number.

```bash
git add -A
git commit -m "feat: render template variables as chips in the settings fields"
```

---

### Task 4: Full gate and push

- [ ] **Step 1: Run every gate**

```bash
make lint
make test
make test-js
make test-e2e
make check-plugin
```

- [ ] **Step 2: Confirm the built asset ships**

```bash
grep -n "dist" .distignore
```

Expected: the comment confirming `/dist/` is deliberately not excluded, and no line excluding it. If `dist/` were excluded, the plugin would work in development and ship without its admin bundle.

- [ ] **Step 3: Push**

```bash
git push
gh pr checks 2 --watch --interval 30
```

Expected: all five CI jobs pass — the four existing plus the new JS Unit job.

---

## A deliberate gap in Task 3, and why

Every other plan in this series gives the implementer verbatim code. Task 3's Steps 3, 6 and 7 do not: they specify behaviour and acceptance criteria instead. That is not an oversight, and it is worth being explicit about rather than discovering mid-task.

The reason is Step 1. Whether the surface is `RichText` inside a `BlockEditorProvider` or a `contenteditable` managed with `@wordpress/rich-text`'s `create()`/`toHTMLString()` changes what the component, the autocomplete integration and the e2e locators look like. Writing one version verbatim would produce code that does not compile under the other route, which is worse than specifying the target precisely — an implementer who follows non-working code tends to bend the surrounding design to fit it.

So Task 3 is specified by what must be true when it is done. Each of those steps carries acceptance criteria below; treat them as the test the work has to pass.

**Step 3 — the field component is done when:**
- A field whose stored value is `%%title%% — Shop` shows one chip reading the human label for `title` (not `title`, and not `%%title%%`) followed by the literal text ` — Shop`.
- A field containing `%%not_registered%%` shows a chip displaying `not_registered`, carrying core's `form-invalid` class.
- Editing anything in the surface writes the canonical text into the original `<input>`, and `serializeSegments( parseTemplate( stored ) ) === stored` still holds for the value that lands there.
- The slug→label map comes from the row's pills. Nothing reads a JSON payload.

**Step 6 — the autocomplete is done when:**
- Typing `%%` opens a suggestion list containing that row's variables and nothing else.
- Typing `%%pri` on a row offering `primary_category` narrows to it.
- Selecting a suggestion inserts a chip, not raw `%%token%%` text.
- Typing `%%` on a row whose pills are empty opens nothing.

**Step 7 — the e2e rewrite is done when:**
- No spec calls `fill()` on a hidden input.
- A spec asserts a stored template renders as chips showing human labels.
- A spec edits through the surface, saves, and asserts the stored value is the expected `%%token%%` text.
- The pre-existing assertions about validation, `.form-invalid`, and pill insertion still exist in some form — they may be retargeted at the new surface, but none may be deleted to make the suite pass.

If the spike's outcome makes any of these criteria impossible rather than merely different, stop and report it. That would mean the spec's chosen approach does not fit, which is a decision for the human, not a thing to work around.

## Notes for the implementer

**Step 1 of Task 3 is a spike, and its outcome changes the rest of the task.** Do it first, report what you found, and do not carry a broken assumption forward. If neither route works, that is a finding worth stopping for, not something to force.

**Never add `wp_localize_script`.** The pills are the only channel. If you find yourself wanting a JSON copy of the registry, stop — that is the exact drift this line of work removed.

**The round trip must be exact.** If a template goes into the surface and comes out different — even only in case — an administrator's stored data has been silently edited by the act of viewing it.

**`dist/` is committed and shipped.** Check whether the repo tracks `dist/breadcrumbs/index.js`; follow whatever it already does for the new bundle rather than inventing a different convention.

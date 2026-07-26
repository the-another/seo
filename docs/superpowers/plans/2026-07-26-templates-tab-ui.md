# Titles & Templates Tab UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Titles & Templates tab explain itself — human-readable type names with their slug retained, a visible label on every input, an intro saying what the two fields do, and section headings with separators between the three groups.

**Architecture:** Presentation only, entirely inside `Admin/SettingsPage::render_templates_tab()` plus one new label-resolving helper. Nothing about storage, validation or template resolution changes. Human names come from `get_post_type_object()`/`get_taxonomy()`, the same calls `render_types_tab()` already makes; inputs are labelled with core's `<fieldset>` pattern; sections use `<h2>` + `form-table` + `<hr>`.

**Tech Stack:** PHP 8.3, WordPress 6.9+, PHPUnit 11 + Brain Monkey + Mockery.

**Spec:** `docs/superpowers/specs/2026-07-26-templates-tab-ui-design.md`

## Global Constraints

- **UI uses established WordPress components only.** No stylesheet, no custom CSS class, no invented markup. `<fieldset>` + `<legend class="screen-reader-text">` is core's pattern for multiple inputs under one `<th>` (see `wp-admin/options-reading.php`, `options-discussion.php`); `<h2>` + `<table class="form-table">` per section is what `do_settings_sections()` emits; bare `<hr>` is styled by core at `wp-admin/css/common.css:880`. If something appears to need styling core does not provide, STOP and report rather than inventing it.
- **This is presentation only.** Do not change what is stored, how templates resolve, or the validation rules. The only non-presentational change in the whole plan is the wording of one error message (Task 4).
- **`name` attributes must not change.** `taseo_settings[title_templates][post:<type>]` and `taseo_settings[description_templates][post:<type>]`, and the `term:`/`system_page:` equivalents, stay exactly as they are — stored data and every existing selector depend on them.
- **One `<tr>` per type must be preserved.** The admin tour and e2e specs locate pills with `tr:has(input[name="…"])`. Splitting a type across two rows breaks them.
- **`data-taseo-template-input` stays on every template input**, and the variable pills stay in their current position inside the `<td>` — the admin script reads both.
- PHP 8.3. Tabs. `array()` long syntax. Yoda conditions. No closing `?>`. `@param`/`@return` on every method. Text domain `the-another-seo`. Escape on output.
- **No version bump.**
- **Run tests:** `make test` (Docker, ~30s). Lint: `make lint`. E2E: `make test-e2e` — **FOREGROUND only**, Bash `timeout` `900000` ms, `docker ps` checked first and any running container allowed to exit. Backgrounding it has stalled agents repeatedly on this branch.
- **Baselines:** `make test` → `OK (308 tests, 916 assertions)`. `make test-e2e` → 29 passed.
- Conventional Commits, imperative mood.
- Branch `feature/webmaster-verification` (PR #2 open).

---

## File Structure

**Modified:**

| Path | Change |
|---|---|
| `includes/Admin/SettingsPage.php` | New `template_row_label()` helper; `render_templates_tab()` rewritten for labels, fieldsets and sections; one error message reworded. |
| `tests/Unit/Admin/SettingsPageTest.php` | Coverage for label resolution, fallback, `for`/`id` pairing, unchanged `name`s, and the reworded error. |

Nothing is created. No JavaScript, CSS, or e2e file is touched — **the existing e2e specs passing unmodified is this plan's proof that it changed only presentation.**

---

### Task 1: The label helper

**Files:**
- Modify: `includes/Admin/SettingsPage.php`
- Test: `tests/Unit/Admin/SettingsPageTest.php`

**Interfaces:**
- Produces: `SettingsPage::template_row_label( string $object_type, string $object_subtype ): string` — private; returns the human-readable name for a row, falling back to the subtype slug.

**Why first:** both the row headings (Task 2) and the validation message (Task 4) call it, and the spec requires them to resolve labels the same way so the screen and its errors cannot disagree.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Admin/SettingsPageTest.php`. Read its `setUp()` first; add whatever `Functions\when()` stubs these need alongside the existing ones.

```php
	public function test_template_row_label_uses_the_registered_post_type_name(): void {
		Functions\when( 'get_post_type_object' )->alias(
			static function ( string $type ): ?object {
				return 'product' === $type
					? (object) array( 'labels' => (object) array( 'name' => 'Products' ) )
					: null;
			}
		);

		$this->assertSame( 'Products', $this->invoke_row_label( 'post', 'product' ) );
	}

	public function test_template_row_label_uses_the_registered_taxonomy_name(): void {
		Functions\when( 'get_taxonomy' )->alias(
			static function ( string $tax ): ?object {
				return 'post_tag' === $tax
					? (object) array( 'labels' => (object) array( 'name' => 'Tags' ) )
					: null;
			}
		);

		$this->assertSame( 'Tags', $this->invoke_row_label( 'term', 'post_tag' ) );
	}

	public function test_template_row_label_names_the_system_pages(): void {
		$this->assertSame( 'Home page', $this->invoke_row_label( 'system_page', 'home' ) );
		$this->assertSame( 'Search results', $this->invoke_row_label( 'system_page', 'search' ) );
		$this->assertSame( 'Not found (404)', $this->invoke_row_label( 'system_page', '404' ) );
	}

	public function test_template_row_label_falls_back_to_the_slug_for_a_deregistered_type(): void {
		Functions\when( 'get_post_type_object' )->justReturn( null );

		$this->assertSame( 'gone_type', $this->invoke_row_label( 'post', 'gone_type' ) );
	}

	public function test_template_row_label_falls_back_to_the_slug_for_a_deregistered_taxonomy(): void {
		Functions\when( 'get_taxonomy' )->justReturn( null );

		$this->assertSame( 'gone_tax', $this->invoke_row_label( 'term', 'gone_tax' ) );
	}
```

Add this helper beside them — the method under test is private, and reflection is the least invasive way to test it directly rather than only through rendered markup:

```php
	/**
	 * Call SettingsPage's private template_row_label().
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @return string Label.
	 */
	private function invoke_row_label( string $object_type, string $object_subtype ): string {
		$method = new \ReflectionMethod( SettingsPage::class, 'template_row_label' );
		$method->setAccessible( true );

		return (string) $method->invoke( $this->page, $object_type, $object_subtype );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `make test`
Expected: FAIL — `ReflectionException: Method … does not exist`

- [ ] **Step 3: Implement the helper**

Add to `includes/Admin/SettingsPage.php`, near the other private render helpers:

```php
	/**
	 * Human-readable name for one template row.
	 *
	 * Post types and taxonomies carry registered labels; render_types_tab()
	 * already reads the same properties. System pages are ours to name.
	 *
	 * Falls back to the subtype slug when nothing is registered under it: a
	 * post type whose plugin has been deactivated leaves its stored
	 * templates behind, and that row must stay identifiable and editable
	 * rather than rendering an empty heading.
	 *
	 * The row heading and the validation error both call this, so the
	 * screen and its error messages cannot describe the same row
	 * differently.
	 *
	 * @param string $object_type    'post', 'term', or 'system_page'.
	 * @param string $object_subtype Post type, taxonomy, or system page key.
	 * @return string Human-readable label.
	 */
	private function template_row_label( string $object_type, string $object_subtype ): string {
		if ( 'post' === $object_type ) {
			$object = get_post_type_object( $object_subtype );

			return isset( $object->labels->name ) ? (string) $object->labels->name : $object_subtype;
		}

		if ( 'term' === $object_type ) {
			$taxonomy = get_taxonomy( $object_subtype );

			return isset( $taxonomy->labels->name ) ? (string) $taxonomy->labels->name : $object_subtype;
		}

		$system_labels = array(
			'home'   => __( 'Home page', 'the-another-seo' ),
			'search' => __( 'Search results', 'the-another-seo' ),
			'404'    => __( 'Not found (404)', 'the-another-seo' ),
		);

		return $system_labels[ $object_subtype ] ?? $object_subtype;
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `make test`
Expected: PASS, higher than 308 tests.

- [ ] **Step 5: Lint and commit**

```bash
make lint
git add includes/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: resolve human-readable labels for template rows"
```

---

### Task 2: Labelled inputs and human row headings

**Files:**
- Modify: `includes/Admin/SettingsPage.php`
- Test: `tests/Unit/Admin/SettingsPageTest.php`

**Interfaces:**
- Consumes: `template_row_label()` (Task 1); the existing `template_input_class( string $settings_key, string $row_key ): string`, which returns `large-text` or `large-text form-invalid`; the existing `render_variable_pills( string $object_type, string $object_subtype ): void`.

- [ ] **Step 1: Write the failing test**

**Every test below must set `$_GET['tab'] = 'templates';` as its first line.** The existing `render_page()` helper *unsets* the tab after rendering but never sets it, so without that line the page renders the General tab and the assertions fail for a reason that has nothing to do with the code under test. The tests already in the file do this — follow them.

Append to `tests/Unit/Admin/SettingsPageTest.php`:

```php
	public function test_template_rows_show_human_labels_with_the_slug_beneath(): void {
		$_GET['tab'] = 'templates';

		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $type ): object => (object) array( 'labels' => (object) array( 'name' => 'Products' ) )
		);

		$html = $this->render_page( array( 'product' ) );

		$this->assertStringContainsString( 'Products', $html );
		$this->assertStringContainsString( '<code>post:product</code>', $html );
	}

	public function test_system_page_rows_show_their_own_names_not_slugs(): void {
		$_GET['tab'] = 'templates';
		$html = $this->render_page();

		$this->assertStringContainsString( 'Not found (404)', $html );
		$this->assertStringContainsString( '<code>system_page:404</code>', $html );
	}

	public function test_each_template_input_has_a_label_bound_by_id(): void {
		$_GET['tab'] = 'templates';
		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $type ): object => (object) array( 'labels' => (object) array( 'name' => 'Posts' ) )
		);

		$html = $this->render_page( array( 'post' ) );

		$this->assertStringContainsString( 'for="taseo-title-post-post"', $html );
		$this->assertStringContainsString( 'id="taseo-title-post-post"', $html );
		$this->assertStringContainsString( 'for="taseo-desc-post-post"', $html );
		$this->assertStringContainsString( 'id="taseo-desc-post-post"', $html );
	}

	public function test_template_input_names_are_unchanged(): void {
		$_GET['tab'] = 'templates';
		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $type ): object => (object) array( 'labels' => (object) array( 'name' => 'Posts' ) )
		);

		$html = $this->render_page( array( 'post' ) );

		$this->assertStringContainsString( 'name="taseo_settings[title_templates][post:post]"', $html );
		$this->assertStringContainsString( 'name="taseo_settings[description_templates][post:post]"', $html );
		$this->assertStringContainsString( 'data-taseo-template-input', $html );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `make test`
Expected: FAIL — the slug `<code>` element and the `for`/`id` attributes do not exist yet.

- [ ] **Step 3: Rewrite the three loops**

Replace the body of `render_templates_tab()`'s post-type loop with this shape, and apply the same shape to the taxonomy loop (`term:` keys, `render_variable_pills( 'term', $tax )`):

```php
		foreach ( $this->settings->get_enabled_post_types() as $type ) {
			$label   = $this->template_row_label( 'post', $type );
			$row_key = 'post:' . $type;

			printf(
				'<tr><th scope="row">%1$s<p class="description"><code>%2$s</code></p></th><td>
					<fieldset>
						<legend class="screen-reader-text"><span>%1$s</span></legend>
						<label for="taseo-title-%3$s">%4$s</label><br />
						<input type="text" id="taseo-title-%3$s" name="taseo_settings[title_templates][%2$s]" value="%5$s" class="%6$s" data-taseo-template-input /><br />
						<label for="taseo-desc-%3$s">%7$s</label><br />
						<input type="text" id="taseo-desc-%3$s" name="taseo_settings[description_templates][%2$s]" value="%8$s" class="%9$s" data-taseo-template-input />
					</fieldset>',
				esc_html( $label ),
				esc_attr( $row_key ),
				esc_attr( 'post-' . $type ),
				esc_html__( 'Title template', 'the-another-seo' ),
				esc_attr( $this->settings->get_title_template( 'post', $type ) ),
				esc_attr( $this->template_input_class( 'title_templates', $row_key ) ),
				esc_html__( 'Meta description template', 'the-another-seo' ),
				esc_attr( $this->settings->get_description_template( 'post', $type ) ),
				esc_attr( $this->template_input_class( 'description_templates', $row_key ) )
			);
			$this->render_variable_pills( 'post', $type );
			echo '</td></tr>';
		}
```

The system-page loop keeps its single input, so it needs no fieldset — one `<label for>` and one input is unambiguous:

```php
		foreach ( array( 'home', 'search', '404' ) as $system ) {
			$label   = $this->template_row_label( 'system_page', $system );
			$row_key = 'system_page:' . $system;

			printf(
				'<tr><th scope="row">%1$s<p class="description"><code>%2$s</code></p></th><td>
					<label for="taseo-title-%3$s">%4$s</label><br />
					<input type="text" id="taseo-title-%3$s" name="taseo_settings[title_templates][%2$s]" value="%5$s" class="%6$s" data-taseo-template-input />',
				esc_html( $label ),
				esc_attr( $row_key ),
				esc_attr( 'system-page-' . $system ),
				esc_html__( 'Title template', 'the-another-seo' ),
				esc_attr( $this->settings->get_title_template( 'system_page', $system ) ),
				esc_attr( $this->template_input_class( 'title_templates', $row_key ) )
			);
			$this->render_variable_pills( 'system_page', $system );
			echo '</td></tr>';
		}
```

**Note the `placeholder` attributes are gone.** They were the only explanation the fields had and they vanished as soon as a field had a value; the visible `<label>` replaces them permanently. Do not keep both.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `make test`
Expected: PASS. If a pre-existing test asserted on a `placeholder`, update it to assert the label instead — and say so in your report.

- [ ] **Step 5: Lint and commit**

```bash
make lint
git add includes/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: label every template input and name rows in plain language"
```

---

### Task 3: Sections, separators and the explanatory intro

**Files:**
- Modify: `includes/Admin/SettingsPage.php`
- Test: `tests/Unit/Admin/SettingsPageTest.php`

- [ ] **Step 1: Write the failing test**

```php
	public function test_templates_tab_explains_what_the_two_fields_do(): void {
		$_GET['tab'] = 'templates';
		$html = $this->render_page();

		$this->assertStringContainsString( 'title', strtolower( $html ) );
		$this->assertStringContainsString( 'meta description', strtolower( $html ) );
		$this->assertStringContainsString( 'class="description"', $html );
	}

	public function test_templates_tab_splits_into_three_titled_sections(): void {
		$_GET['tab'] = 'templates';
		$html = $this->render_page();

		$this->assertStringContainsString( '<h2>Post types</h2>', $html );
		$this->assertStringContainsString( '<h2>Taxonomies</h2>', $html );
		$this->assertStringContainsString( '<h2>System pages</h2>', $html );
		$this->assertSame( 2, substr_count( $html, '<hr />' ), 'separators sit between the three sections, not after the last' );
		$this->assertSame( 3, substr_count( $html, '<table class="form-table">' ) );
	}

	public function test_system_pages_section_explains_it_has_no_description_field(): void {
		$_GET['tab'] = 'templates';
		$this->assertStringContainsString(
			'System pages take a title template only.',
			$this->render_page()
		);
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `make test`
Expected: FAIL — there is one table, no headings, no `<hr />`.

- [ ] **Step 3: Add the sections**

Restructure `render_templates_tab()` so each group is a heading, its own table, and `<hr />` between them. The three loops are already in the method from the previous task and their bodies do not change — only what surrounds them. Open the method with the intro; the closing `</table>` for each group goes after its own loop:

```php
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The title template becomes the page\'s title element; the meta description template becomes its meta description. Leave a field empty to use the default: "%%title%% %%sep%% %%sitename%%" for titles and "%%excerpt%%" for descriptions.', 'the-another-seo' )
		);

		echo '<h2>' . esc_html__( 'Post types', 'the-another-seo' ) . '</h2>';
		echo '<table class="form-table">';
		// … the post-type loop already in this method, unchanged …
		echo '</table>';

		echo '<hr />';

		echo '<h2>' . esc_html__( 'Taxonomies', 'the-another-seo' ) . '</h2>';
		echo '<table class="form-table">';
		// … the taxonomy loop already in this method, unchanged …
		echo '</table>';

		echo '<hr />';

		echo '<h2>' . esc_html__( 'System pages', 'the-another-seo' ) . '</h2>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'System pages take a title template only.', 'the-another-seo' )
		);
		echo '<table class="form-table">';
		// … the system-page loop already in this method, unchanged …
		echo '</table>';
```

The heading strings reuse the wording `render_types_tab()` already prints for its own two groups, so the tabs read consistently.

`<hr />` needs no styling from us — WordPress admin's `wp-admin/css/common.css` styles bare `hr` at line 880 (`border-top: 1px solid #dcdcde`). Do not add a class to it.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `make test`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
make lint
git add includes/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: split the templates tab into titled sections with an intro"
```

---

### Task 4: Validation errors name the row in plain language

**Files:**
- Modify: `includes/Admin/SettingsPage.php` (the `add_settings_error()` call, around line 911)
- Test: `tests/Unit/Admin/SettingsPageTest.php`

**Interfaces:**
- Consumes: `template_row_label()` (Task 1).

- [ ] **Step 1: Write the failing test**

```php
	public function test_validation_error_names_the_row_in_plain_language(): void {
		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $type ): object => (object) array( 'labels' => (object) array( 'name' => 'Products' ) )
		);
		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn( array() );

		$message = '';

		Functions\when( 'add_settings_error' )->alias(
			function ( string $slug, string $code, string $text ) use ( &$message ): void {
				$message = $text;
			}
		);

		$this->page->sanitize_settings(
			array( 'title_templates' => array( 'post:product' => '%%title%% %%discount%%' ) ),
			'templates'
		);

		$this->assertStringContainsString( 'Products', $message );
		$this->assertStringNotContainsString( 'post:product', $message );
	}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `make test`
Expected: FAIL — the message currently begins `post:product:`.

- [ ] **Step 3: Use the label in the message**

In the `add_settings_error()` call, split the row key and pass the resolved label instead of the raw key. The `$code` argument is **unchanged** — `collect_invalid_rows()` matches on it to apply `.form-invalid`, so altering it would silently stop the failed field being marked:

```php
					$parts     = explode( ':', $row_key, 2 );
					$row_label = $this->template_row_label( $parts[0] ?? '', $parts[1] ?? '' );

					add_settings_error(
						'taseo_messages',
						self::INVALID_TEMPLATE_CODE . $tpl_key . '__' . $row_key,
						sprintf(
							/* translators: 1: row label such as Products, 2: comma-separated variable tokens. */
							esc_html__( '%1$s: %2$s is not available for this content type. That field was not saved; the others were.', 'the-another-seo' ),
							esc_html( $row_label ),
							esc_html( implode( ', ', $invalid ) )
						),
						'error'
					);
```

The surrounding validation loop already computes `$parts`/`$type`/`$subtype` for the availability check — reuse those variables rather than re-splitting, if they are in scope at this point.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `make test`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
make lint
git add includes/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: name the row in plain language in template validation errors"
```

---

### Task 5: Prove nothing but presentation changed

**Files:** none — this task verifies.

- [ ] **Step 1: Run the e2e suite unmodified**

Run `make test-e2e` in the FOREGROUND, Bash `timeout` `900000` ms, `docker ps` checked first.

Expected: **29 passed**, with **no edits to any file under `tests/e2e/`**.

This is the plan's central check. `webmaster-admin.spec.ts` and `zz-admin-tour.spec.ts` drive these fields by `name` and locate pills with `tr:has(input[name="…"])`. If they still pass untouched, the row structure, the input names and the pills' position all survived — which is what "presentation only" means in practice.

**If a spec fails, do not edit it.** The markup changed more than this plan intends. Report which spec, which assertion, and what the markup now looks like.

- [ ] **Step 2: Run the rest of the gate**

```bash
make lint
make test
make check-plugin
```

All green. `make test` should be well above the 308 baseline.

- [ ] **Step 3: Confirm no stylesheet crept in**

```bash
grep -rn "wp_enqueue_style\|\.css" includes/ ; echo "exit=$?"
```

Expected: no matches. Any hit means a stylesheet was introduced, which the constraints forbid.

- [ ] **Step 4: Push**

```bash
git push
gh pr checks 2 --watch --interval 30
```

Expected: all four CI jobs pass.

---

## Notes for the implementer

**Do not touch `tests/e2e/`.** Those specs passing unmodified is this plan's evidence. A change there converts the evidence into an assumption.

**Do not add a stylesheet or a CSS class.** `.form-table`, `.description`, `.screen-reader-text`, `.button-small` and bare `<hr>` are all core-styled. If something looks wrong, check whether core already styles it before reaching for CSS — and if core genuinely lacks it, stop and report.

**The `placeholder` attributes are removed in Task 2, deliberately.** They were the fields' only explanation and disappeared the moment a field had a value. Keeping both a placeholder and a label would be redundant.

**The error `$code` in Task 4 must not change.** `collect_invalid_rows()` parses it to apply `.form-invalid` to the failed field; only the human-readable `$message` changes.

**A follow-up spec already depends on this one.** `docs/superpowers/specs/2026-07-26-template-token-chips-design.md` replaces the editing surface with rich-text chips and requires the `<label>` this plan adds, because mounting a surface over the input loses its `placeholder`. Do not start that work here.

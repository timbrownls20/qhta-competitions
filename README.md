# QHTA Competitions

WordPress plugin for **qhta.com.au** that runs QHTA's submission-based programs
— the **Historical Writing Competition** and the **ECC Bursary** — as configurable
*program rounds*: call for submissions → review → outcomes. It replaces the
emailed PDF forms. A future program (an award, a grant) is a new round, not new code.

- Spec: [docs/SPEC.md](docs/SPEC.md)
- Changes: [docs/CHANGELOG.md](docs/CHANGELOG.md)
- Deploying and the live smoke test: [docs/DEPLOY.md](docs/DEPLOY.md)
- Privacy text: [docs/PRIVACY.md](docs/PRIVACY.md)
- QHTA Health canaries: [docs/HEALTHCHECK.md](docs/HEALTHCHECK.md)

## Status

**1.0.0 — all milestones (M1–M6) built and tested locally.** It isn't deployed
yet: follow [docs/DEPLOY.md](docs/DEPLOY.md) (staging, then live), which also
lists the checks that can only be done on a real server and the open decisions
(reference-code format, PMPro levels, preset wording).

## Requirements

- WordPress 6.5+, PHP 7.4+.
- **wordpress-feature-flags 1.0.0**, installed as the folder
  `wp-content/plugins/wordpress-feature-flags/`. The repo is
  `wordpress-feature-flag` (singular), so a plain `git clone` into `plugins/`
  gives the wrong folder name and WordPress reports the dependency
  (`Requires Plugins: wordpress-feature-flags`) as missing.
- `WPFF_PREVIEW_TOKEN` (12+ characters) in `wp-config.php`, for secret
  preview links.
- Site time zone **Australia/Brisbane**. Dates are stored in UTC and shown in
  the site time zone.
- Optional, never hard dependencies:
  - PMPro — the membership check;
  - WooCommerce — not used by the plugin, but judges who are WooCommerce
    customers are let into wp-admin;
  - SureMail — the plugin uses `wp_mail`.

## Running a round

### 1. Set up

1. **Competitions → New from preset**:
   - *ECC Bursary 2027* — the real first round;
   - *Historical Writing Competition 2026 (demo)* — for showing the committee;
   - or *Add blank round*.

   **Next year's round:** on *Program rounds*, hover the round and click
   *Copy for next year* (also in the round's Publish box). You get a draft
   with every setting copied, years in the title moved on by one (*ECC
   Bursary 2027* → *ECC Bursary 2028*), the open and close dates a year
   later, and its own feature flag. Entries and scores are not copied. A
   notice lists any boxes whose wording still mentions the old year.
2. Edit the round. Everything is point-and-click:
   - **Form fields:** *Add a question*, pick its type (short answer, dropdown,
     tick box, file upload…), and click a question to edit its wording,
     choices, *Must be answered*, *Personal information* (hidden from judges,
     removed after retention) and *Show only when…*. Drag ☰ or use the arrows
     to reorder; a removed question can be undone.
   - **Categories, Declarations, Judging rubric:** one row per item, with
     *Add* buttons.
   - **Eligibility rules:** tick boxes that must be ticked, the membership
     check and the different-referee check, each set to *stop the entry* or
     *accept it, but flag it ⚑*.
   - **Email templates:** one card per email, with *Send this email* and
     *Insert* buttons for the reference, names and so on.
   - Each box keeps *Advanced: edit as JSON* for power users. Invalid JSON
     is never saved and never lost — it comes back in the box with the error.
3. **Publish.** The round gets a feature flag `comp-{slug}` (see its *Feature
   flag* box), starting in **preview**. The key never changes, even if the
   slug does.
4. Put the shortcode from the *Shortcode* box on a page.
   - **Dedicated page:** tag the page with the round's flag in wpff's Feature
     flag box.
   - **Existing page** (e.g. the ECC page): don't tag the page. Wrap the
     form's section in wpff's Feature flag block instead, so the rest of the
     page stays visible.

### 2. Test, then open

- **Preview:** admins, and anyone with the secret link, see the form with a
  PREVIEW banner and can submit at any time.
  - Those entries are `TEST-…`, emails say `[TEST]`, and they're left out of
    limits (except among themselves), judging, exports and outcome emails.
  - Clear them with **Submissions → Tests → Delete test entries**.
- **Open:** Tools → Feature flags → the round → **on**. The public sees the
  form between *Opens* and *Closes* (+ grace), and "opens {date}" / "closed"
  messages otherwise.

### 3. While open

- Each submission gets a reference (`QC-2027-0001`). The submitter and the
  admins are emailed.
- Declarations are signed by typing a name, recorded with time, IP and browser.
- Files are .docx/.pdf only and stored privately. They download as `{ref}.docx`.
- Word counts: .docx files are counted automatically (footnotes in,
  bibliography out). Inline pieces over the limit are blocked; uploads are
  only flagged.
- **Referee rounds** (the bursary) email the referee a single-use link.
  - The entry stays *Awaiting confirmation* until they confirm.
  - Links expire 7 days after close; referees are reminded once, 48 hours
    before close.
  - On the submission screen: *Re-send link* (the old one stops working) or
    *Mark confirmed* (note required).
- **Eligibility:**
  - Block rules stop the form (e.g. the "outside SEQ" tick).
  - Flag rules accept the entry and mark it ⚑.
  - Membership shows as ✓ / ✗ / ? (PMPro, for logged-in applicants) and can be
    set by hand, along with the *QCT verified* tick.
  - **Entry limits** match on name + school, ignoring case and punctuation.

### 4. Judging

1. **Competitions → Judges:** add existing accounts by username or email.
   This adds a secondary judge role; their own role and membership are
   untouched. Set each judge's school — entries from that school are hidden
   from them.
2. Each round's **Judging panel** box: tick judges per category.
3. **Submissions →** select entries → **Send to judging**.
4. Judges land on **Judging** after login. They see reference codes only,
   while the round is anonymised, plus the entry and a rubric. They can't see
   other categories, lists, exports or anyone else's scores.
5. **Competitions → Results** per category:
   - shows rank, each judge's %, the mean, and a ⚠ when judges differ by more
     than the round's spread setting;
   - bulk shortlist, then award / commend / unsuccessful.

Judge % = Σ(score ÷ max × weight) ÷ Σ weight × 100, once every criterion is
scored. Mean = average of the judges' %s. Equal means share a rank.

### 5. Outcomes

- **Submissions →** select → **Notify outcome**. The preview page shows each
  email as it will be sent and what's skipped. **Send me a test** is
  required, then **Send**. Emails go in batches of 20 a minute via WP-Cron,
  once per outcome per entry.
- **Bursary free registration** is arranged by hand. The award email says
  QHTA will arrange registration (`{{registration_instructions}}`); an admin
  then creates the complimentary order.

### 6. Afterwards

- **Exports:** the entries CSV (with or without personal details) and the
  publication-consent register. Excel-ready. Test entries are never included,
  and judges never get exports.
- **Retention:** a set time after close (12 months by default), entries not
  awarded or commended lose their personal details and files. The Retention
  page shows the date and the list in advance.
- **Privacy requests:** Tools → Export / Erase Personal Data covers entries
  and referee details. See [docs/PRIVACY.md](docs/PRIVACY.md).
- **Health** (any time): upload protection self-test, cron heartbeat, mail
  test, flags, PMPro, database.

## Reference

### Feature flags

| Flag | Default | Meaning |
|---|---|---|
| `competitions` | on | Kill switch for every form. Off = "temporarily unavailable"; referee links pause (stay valid). |
| `comp-{round}` | preview | One per round. Preview = admins and secret-link holders (test entries). On = public, subject to the dates. Off = hidden. Referee links and admin screens are never gated by it. |

A trashed round's flag stays registered until the round is permanently
deleted, so pages still using it fail closed and show under wpff's "Where used".

### Form fields

Types: `text`, `email`, `tel`, `textarea`, `richtext_limited` (inline piece,
word-limited by the category), `select`, `radio`, `checkbox`, `year_level`,
`address`, `file` (.docx/.pdf), `heading`, `help`. Each field has `key`,
`type`, `label`, `required`, `help`, `options`, `pii` and `judge_visible`,
plus these optional keys:

| Key | Values | Used for |
|---|---|---|
| `role` | `entrant_name`, `school`, `submitter_email` | List columns, confirmation recipient, entry limits, conflict of interest |
| `prefill` | `name`, `email`, `phone`, `address` | Prefill for logged-in users (account / PMPro billing) |
| `show_if` | `{field, equals}` | Conditional field (a checkbox's value is `"1"`) |
| `label_if` | `{field, equals, label}` | Swap the label (bursary referee when not yet in post) |
| `admin_verify` | `true` | Adds a "verified" tick on the submission (QCT number) |

`pii: true` fields are hidden from judges, left out of the standard export,
and purged by retention. A text field keyed `word_count_declared` is the
declared word count.

### Emails

Per-round templates are keyed by event: `submitted`, `admin_new`,
`confirm_request`, `confirmed`, `reminder_confirm`, `outcome_awarded`,
`outcome_commended`, `outcome_unsuccessful`.

Tokens: any field key, plus `{{ref}}`, `{{program}}`, `{{category}}`,
`{{status}}`, `{{closes_at}}`, `{{confirm_url}}`, `{{confirm_expires}}`,
`{{admin_url}}`, `{{flags}}` and `{{registration_instructions}}`. Sections use
`{{#x}}…{{/x}}` and `{{^x}}…{{/x}}`.

HTML uses `templates/email.php`, always with a plain-text part. To override
`email.php`, `form.php` or `confirm-page.php` without editing the plugin, copy
it to `wp-content/uploads/qhta-competitions/templates/`. Admin notifications
go to the site admin email (filter `qc_admin_recipients`).

### Data

| Thing | Where |
|---|---|
| Rounds | CPT `qhta_program`; config in `_qc_*` post meta (JSON); panel in `_qc_panel` |
| Submissions | CPT `qhta_submission` (`post_parent` = round), statuses `qc_*`, history in `_qc_log` |
| Scores | Table `{prefix}qc_scores` (dbDelta; schema version in `qc_db_version`) |
| Files | `wp-content/uploads/qhta-competitions/{round}/` — `.htaccess` deny-all + `index.php`, random names, never a public URL |
| Options | `qc_round_flags`, `qc_outcome_queue`, `qc_cron_last`, `qc_retention_last_run` — all autoload no; no transients |
| Cron | `qc_cron` hourly (reminders, daily retention, outcome safety net); `qc_outcome_batch` |

Uninstalling keeps all data unless you opt in first:
`wp option update qc_delete_data_on_uninstall 1`.

### Hooks

- `qc_validate_submission`, `qc_submission_flags` — extra eligibility.
- `qc_submission_created` — after a submission is saved.
- `qc_email_tokens` — add or override email tokens.
- `qc_admin_recipients` — who gets "new submission" emails.
- `qc_min_seconds_on_page` — anti-bot timing (default 4).
- `qc_judge_can_access_submission` — file access for judges.
- `qc_health_probe_url` — the URL the Health self-test fetches.

## Development

There's no build step: just PHP, plain ES5 and CSS in `assets/`. Conventions
are in spec §6:
- prefix `qc_` / `Qhta_Competitions_`;
- page-scoped assets;
- `class-flags.php` is the only caller of `wpff_*`;
- no ACF, no transients.

### Tests

Against a **disposable** site only — they flip flags, rewrite dates, and
`m1-smoke.php setup` deletes every round and submission. The local set-up was
WordPress in Docker plus wordpress-feature-flags and Mailpit, with WooCommerce
(for the judge-access checks) and a `pmpro_hasMembershipLevel()` stub or PMPro.

```sh
# Reset + structural checks (run before the HTTP suites)
wp eval-file wp-content/plugins/qhta-competitions/tests/m1-smoke.php setup  --user=admin
wp eval-file wp-content/plugins/qhta-competitions/tests/m1-smoke.php verify --user=admin

# HTTP suites
export BASE=http://localhost:8089 WP="docker exec qctest-cli-1 wp" \
       MAILPIT=http://localhost:8025 PREVIEW_TOKEN=…
python3 tests/m2-http-smoke.py   # form, flag matrix, uploads, word count, emails
python3 tests/m3-http-smoke.py   # referee links, reminders, eligibility, limits
python3 tests/m4-http-smoke.py   # judges, access, scoring, results vs hand calc
python3 tests/m5-http-smoke.py   # outcome emails, exports, retention, privacy, health
```

Fixtures: `tests/fixtures/make-fixtures.py` (three .docx files with known word
counts) and `tests/fixtures/make-judging-fixture.py` (judging scores, the
expected ranks, and `judging-handcalc.csv` with live formulas).

### Release

1. Bump the version in **both** the plugin header and `QC_VERSION`.
2. Update `docs/CHANGELOG.md`.
3. Run `./scripts/build-zip.sh`. It refuses to build if the versions differ,
   then lints PHP, the preset JSON and the script, and zips without tests,
   docs or tooling.
4. Deploy per [docs/DEPLOY.md](docs/DEPLOY.md). After every deploy,
   `curl -I https://…/wp-content/uploads/qhta-competitions/index.php` must
   return **403**.

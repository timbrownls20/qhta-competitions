# QHTA Competitions

WordPress plugin for **qhta.com.au** that runs QHTA's submission-based programs
— the **Historical Writing Competition** and the **ECC Bursary** — as configurable
*program rounds*: call for submissions → review → outcomes. It replaces the
emailed PDF forms. A future program (an award, a grant) is a new round, not new code.

Full spec: [docs/SPEC.md](docs/SPEC.md). Changes: [docs/CHANGELOG.md](docs/CHANGELOG.md).

## Status

**M3 done** — referee confirmation by emailed link, reminders, eligibility
rules, membership check and entry limits, on top of M2 (public form, uploads,
word count, emails, flag gating, test entries) and M1 (CPTs, admin UI,
presets, flags). Next: M4 judging; M5 outcomes, coupon, exports, retention,
Health panel. See spec §8.

## Requirements

- WordPress 6.5+, PHP 7.4+.
- **wordpress-feature-flags 1.0.0**, installed as the folder
  `wp-content/plugins/wordpress-feature-flags/`. The repo is
  `wordpress-feature-flag` (singular), so a plain `git clone` into `plugins/`
  gives the wrong folder name and WordPress reports the dependency
  (`Requires Plugins: wordpress-feature-flags`) as missing.
- Optional: `WPFF_PREVIEW_TOKEN` (≥12 chars) in `wp-config.php` for secret preview links.
- Optional integrations, never hard dependencies: PMPro (membership check, M3),
  WooCommerce (bursary coupon, M5).
- The site time zone should be **Australia/Brisbane**. Dates are stored in UTC
  and shown in the site time zone.

## Using it

1. **Competitions → New from preset** → *Create round*. Two presets ship:
   - *ECC Bursary 2027* — the real first round.
   - *Historical Writing Competition 2026 (demo)* — demo data for the committee.
   A blank round can be added with *Add blank round*.
2. Edit the round. Simple settings are form fields; categories, form fields,
   declarations, eligibility rules, rubric and email templates are validated
   JSON (the reference for each is above its box). Invalid JSON is never saved
   and never lost — it comes back in the box with the error.
3. **Publish.** On first save the round gets a feature flag, `comp-{slug}`
   (shown in the *Feature flag* box). The key never changes afterwards, even if
   the slug does.
4. Put the shortcode from the *Shortcode* box on the round's page, and tag that
   page with the same flag in wpff's Feature flag box.
5. While the round flag is in **preview**, admins (and anyone with the secret
   link) see the form with a PREVIEW banner and can submit at any time; those
   entries are `TEST-…`, emails say `[TEST]`, and they're left out of limits,
   judging, exports and outcomes. Clear them with **Submissions → Tests →
   Delete test entries**. Set the flag **on** to open the round to the public,
   subject to its dates.

### Form fields

Field types: `text`, `email`, `tel`, `textarea`, `richtext_limited` (inline
piece, word-limited by the category), `select`, `radio`, `checkbox`,
`year_level`, `address` (street, suburb, state, postcode), `file`
(.docx/.pdf), `heading`, `help`. Optional hints:

| Key | Values | Used for |
|---|---|---|
| `role` | `entrant_name`, `school`, `submitter_email` | List columns, who gets the confirmation email, entry limits (M3) |
| `prefill` | `name`, `email`, `phone`, `address` | Prefilling for logged-in users (WP account / PMPro billing) |
| `show_if` | `{field, equals}` | Show only when another field has a value (checkbox value is `"1"`) |
| `label_if` | `{field, equals, label}` | Swap the label (bursary referee when not yet in post) |

A text field keyed `word_count_declared` is the declared word count.

### Referee confirmation

Rounds with a confirmer (the bursary) email the referee a single-use link when
an application comes in; the entry stays *Awaiting confirmation* until they
confirm. Links expire 7 days after the round closes. Referees are reminded
once, 48 hours before close. From the submission screen an admin can re-send
the link (the old one stops working) or mark it confirmed with a note. The
`competitions` kill switch pauses confirmations; turning a round's own flag
off does not.

### Eligibility and limits

Per round, in the *Eligibility rules* and *Entry limits* boxes. Block rules
stop the form; flag rules accept it and mark it (⚑ in the list). Membership
shows as ✓/✗/? in the **Member?** column and can be set by hand in the
submission's *Admin checks* box, along with "verified" ticks for fields
marked `"admin_verify": true` (e.g. the QCT number).

### Email

Templates are per round (Email templates box). HTML uses
`templates/email.php`; a plain-text part is always included. To change
the HTML wrapper or the form layout without editing the plugin, copy
`templates/email.php` or `templates/form.php` to
`wp-content/uploads/qhta-competitions/templates/`. Admin notifications go to
the site admin email (filter `qc_admin_recipients`).

### Feature flags

| Flag | Default | Meaning |
|---|---|---|
| `competitions` | on | Kill switch for every form. Off = "temporarily unavailable"; referee confirmations pause. |
| `comp-{round}` | preview | One per round. Preview = admins and secret-link holders can see the form and make test entries. On = public, subject to the round's open/close dates. Off = hidden. |

Round lifecycle: create → *preview* (committee tests) → set *on* in
**Tools → Feature flags** → after closing, leave *on* (form says closed) or set
*off* to hide the page too. A trashed round's flag stays registered until the
round is permanently deleted, so pages still using it fail closed and show up
under wpff's "Where used".

## Data

| Thing | Where |
|---|---|
| Rounds | CPT `qhta_program`, config in `_qc_*` post meta (JSON) |
| Submissions | CPT `qhta_submission` (`post_parent` = round), statuses `qc_*`, transition log in `_qc_log` |
| Scores | Table `{prefix}qc_scores` (dbDelta, version in option `qc_db_version`) |
| Files | `wp-content/uploads/qhta-competitions/` — `.htaccess` deny-all + `index.php`; never a public URL |
| Round flags cache | Option `qc_round_flags` (autoload no) |

All plugin options are `autoload=no`; no transients (Hostinger object-cache issue).

Uninstall keeps all data unless you opt in first:
`wp option update qc_delete_data_on_uninstall 1`.

## Development

No build step: PHP plus plain ES5/CSS in `assets/`.

### Smoke tests

Against a **disposable** site with both plugins active (setup deletes all rounds):

```sh
wp eval-file wp-content/plugins/qhta-competitions/tests/m1-smoke.php setup  --user=admin
wp eval-file wp-content/plugins/qhta-competitions/tests/m1-smoke.php verify --user=admin
```

```sh
# M2 over HTTP (needs M1 setup first; Mailpit optional for the email checks)
BASE=http://localhost:8089 WP="wp --path=/var/www/html" MAILPIT=http://localhost:8025 \
PREVIEW_TOKEN=… python3 tests/m2-http-smoke.py
```

`m1-smoke.php setup` checks activation (table, role, caps, protected upload dir), loads both
presets, round-trips each config through the admin save handler, checks
validation, flag keys (clash suffixing, truncation, rename stability, trash and
delete), and the status log. `verify` (a fresh request) checks the flags are
registered with wpff with the right defaults and owner.

`m3-http-smoke.py` (same environment variables; needs Mailpit and either
PMPro or a stub `pmpro_hasMembershipLevel()`) covers the referee link end to
end, expiry, reuse, re-send, manual confirmation, reminders, membership
detection, eligibility flags/blocks and entry limits.

`m2-http-smoke.py` drives the real site: the flag matrix (wpff inactive /
`competitions` off / round off / preview as admin, secret link and logged out /
on before, during and after the dates), preview and live bursary submissions,
validation, honeypot and timing checks, comp uploads with word counts and
flags, protected file access (direct URL 403, admin download, refused for
others), and emails (HTML + text, `[TEST]` prefixes).

Word-count fixtures: `python3 tests/fixtures/make-fixtures.py` rebuilds the
three .docx files and `expected.json`.

### Release steps

1. Bump the version in **both** the plugin header and `QC_VERSION`
   (`qhta-competitions.php`).
2. `scripts/check-version.sh` — fails if they differ.
3. Update `docs/CHANGELOG.md`.
4. After deploying, `curl -I https://…/wp-content/uploads/qhta-competitions/index.php`
   must return **403** (LiteSpeed honouring the `.htaccess` deny).

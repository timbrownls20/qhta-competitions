# Changelog

## 0.5.0 — M5 (unreleased)

### Added
- **Outcome emails** (`class-outcomes.php`, Competitions → Outcome emails).
  1. Select entries in Submissions and use the **Notify outcome** bulk action.
  2. The preview page shows each outcome's email rendered for a real entry,
     how many will go, and what's skipped and why: test entries, no final
     outcome, no template, no email, or already notified (unless you choose to
     re-send).
  3. **Send me a test** sends each one to you as `[PREVIEW]`. **Send N emails**
     stays disabled, and is refused server-side, until you've test-sent that
     exact selection.
  4. Emails are queued (`qc_outcome_queue`) and sent by WP-Cron in batches of
     20, one minute apart. Status is re-checked at send time, sends are
     recorded in `_qc_outcome_notified`, and there's an hourly safety net.
- **Bursary coupon** (`class-bursary.php`, loaded only with WooCommerce;
  **off by default — Q12**). With `coupon_enabled`, awarding creates a
  single-use, individual-use 100% coupon `ECCB-{ref}`:
  - limited to the configured product (or variation) and the applicant's email;
  - the entry is recorded on the coupon;
  - it goes into the award email via `{{registration_instructions}}`.
  With the coupon off, that token reads: "QHTA will arrange your free
  conference registration and email you the details — you do not need to
  register or pay." Forfeiting voids (trashes) an unused coupon; a used one is
  left and logged for manual follow-up. No coupon for test entries.
  WooCommerce core APIs only.
- **Exports** (`class-export.php`, Competitions → Exports, admins only).
  - **Entries CSV**: fields, category, word counts, flags, Member?, verified
    ticks, each declaration, consent, referee confirmation, rank, mean, each
    judge's %, spread warning, outcome-email date.
  - Personal information only in the separate **with personal details**
    download.
  - **Publication consent register**: awarded/commended entries with consent ✓,
    entrant, school, year level, consent time and text, file name, admin link.
  - UTF-8 BOM, CRLF, consistent columns; cells starting `= + - @` are prefixed
    with `'` (formula injection). Test entries are never exported; judges get a
    403.
- **Retention** (`class-retention.php`, Competitions → Retention).
  - Runs daily from the hourly cron. `retention_months` after close, every
    entry not awarded or commended has its PII fields, files, typed signature,
    IP and browser, referee details, contact email and match keys removed.
    References, outcomes, non-PII answers and scores stay. Logged, recorded in
    `_qc_purged`, irreversible.
  - The page is a dry run per round ("N entries will be purged on {date}", with
    the list); due rounds get **Purge now** behind a confirmation tick.
- **Privacy tools**: WordPress personal-data exporter and eraser, keyed on
  email (case-insensitive). Covers applicants/teachers and referees. The eraser
  purges as above but keeps awarded/commended entries and says so.
- **Health** (Competitions → Health):
  - feature flags;
  - upload protection self-test: a probe file is written and fetched over
    HTTP, and a 200 is a fail (filter `qc_health_probe_url` for servers that
    can't reach their own hostname);
  - WP-Cron heartbeat (`qc_cron_last`) and DISABLE_WP_CRON note;
  - outcome queue;
  - the coupon's WooCommerce/product dependencies (only when a round uses it);
  - PMPro availability;
  - database version;
  - **mail test** using the plugin's template, reporting `wp_mail_failed` errors.
- `qc_email_tokens` filter on email tokens.
- `tests/m5-http-smoke.py`. `tests/m1-smoke.php setup` now also deletes test
  submissions (and their files).

### Notes
- **Reference codes repeat across rounds closing in the same year.** The spec's
  `QC-{year}-{nnnn}` is a per-round sequence, so e.g. a 2027 bursary and a 2027
  writing competition both produce `QC-2027-0001`. Everything in the plugin
  works per round so this is safe internally, and coupon codes are now
  de-duplicated. But two different entries can share a reference in emails and
  conversations. Decision needed — see the M5 hand-over.
- The coupon was tested against WooCommerce 10.2.2 locally:
  - an order with the registration product and the applicant's email comes to $0;
  - a different email is refused;
  - another product isn't discounted.
  The real **Events checkout** run on staging (product 4311) is still to do.
- "Opens clean in Excel" was checked structurally (BOM, CRLF, quoting,
  consistent columns, parsed as CSV); open one in Excel to confirm.

## 0.4.0 — M4 (unreleased)

### Added
- **Judges** (Competitions → Judges): add an existing account by username or
  email. This gives it the secondary `qhta_judge` role (`read` + `qc_judge`) via
  `WP_User::add_role()`. The user's own role(s) and PMPro membership are
  untouched; removing uses `remove_role()` and also takes the user off every
  panel (their scores are kept). Each judge has a **School** for
  conflict-of-interest checks.
- **Judging panel** box on each round: assign judges per category.
- **Send to judging** bulk action on Submissions (Submitted → In review; test
  entries and anything still awaiting a referee are skipped).
- **Judge dashboard** (wp-admin → Judging; judges land there after login):
  - Lists assigned categories with progress bars ("4 of 6 scored") and entries
    by **reference code only** while the round is anonymised.
  - The scoring screen shows only judge-visible, non-PII fields, the inline
    piece or a download link, and a rubric form (0–max, half points, a comment
    per criterion and overall). Judges can save part-way; *Save and go to next*
    jumps to the next unscored entry.
  - Judges see only their own scores.
- **Access rules** (`Qhta_Competitions_Judging::judge_can_access()`): a real
  entry, In review or Shortlisted, in a category the judge is on, and not from
  the judge's school. The same rule gates protected downloads, so tampering with
  the ID in a download link gets a 403 (nonce, then capability). Judges can't
  reach the submissions list, the submission edit screen, results or exports.
- **Scores** in `{prefix}qc_scores` (one row per submission × judge ×
  criterion; overall comment in `_overall`).
- **Results** (Competitions → Results), per round and category:
  - Rank, each judge's %, mean %, spread and "scored n / m", with conflicts of
    interest shown as COI.
  - A ⚠ when judges differ by more than the round's **Judge spread warning**
    (new round setting, default 15 points).
  - Bulk status change (Shortlisted / Awarded / Commended / Unsuccessful) that
    follows the state diagram and reports any entries it couldn't move. No
    emails are sent.
  - Formula: judge % = Σ(score ÷ max × weight) ÷ Σ weight × 100, counted only
    when every criterion is scored; mean = average of complete judge %s;
    equal means (2 dp) share a rank.
- **Judges' scores** box on the admin submission screen (each judge's scores
  and comments).
- `tests/m4-http-smoke.py`, `tests/fixtures/make-judging-fixture.py` →
  `judging-scores.json`, `judging-expected.json` (computed independently of
  the plugin) and `judging-handcalc.csv` (the same scores with live Excel
  formulas, for checking by hand).

### Notes
- Judges are usually subscribers or customers. WooCommerce (and PMPro's "block
  dashboard" setting) keep those users out of wp-admin, so this plugin lets
  **judges only** through (`woocommerce_prevent_admin_access`,
  `pmpro_block_dashboard`, `woocommerce_disable_admin_bar`). Check this on
  staging with a real judge account.
- Membership: PMPro stores memberships in its own tables, so adding or removing
  a role can't change them. Locally this was shown with the test stub; confirm
  it with real PMPro on staging.
- Anonymity covers form fields only. A judge can still see a name typed inside
  an uploaded document (the preset help text asks entrants not to).
- `judging-handcalc.csv` uses AVERAGEIFS/MAXIFS/MINIFS and array SUMPRODUCT —
  Excel 2019/365 or Google Sheets.

## 0.3.0 — M3 (unreleased)

### Added
- **Referee confirmation** (`class-confirm.php`, spec §5.3). On submit, a
  referee round emails the referee a single-use link `/?qc_confirm={token}`
  (32 random bytes; only the SHA-256 is stored, as `_qc_confirm_hash` and
  `token_hash` in `_qc_confirmation`). Links expire 7 days after the round
  closes, worked out at check time, so moving the close date moves the expiry.
  The page uses the theme header/footer (`templates/confirm-page.php`,
  overridable) and is noindex and uncached. It shows a summary of what the
  referee is vouching for: no files and no contact details (address, phone,
  email). The referee ticks the round's confirmer declaration, types their name
  and can add a comment. Confirming moves the entry to *Submitted*, logs it,
  records name, comment, declaration, time, IP and browser, and emails the
  applicant (`confirmed`).
  - Reused link → "already confirmed" (POST ignored); unknown/malformed →
    "not recognised"; expired → "expired"; withdrawn/ineligible → "no longer
    needed"; a link replaced by a re-send or reminder → "replaced".
  - Not gated by the round flag. The `competitions` kill switch pauses
    confirmations ("temporarily unavailable"); the token stays valid.
  - Admin "Referee confirmation" box: status, sent/expiry/reminder times,
    referee comment, **Re-send confirmation link** (new token, old one
    retired) and **Mark confirmed** (note required, logged).
- **Reminders**: hourly `qc_cron` sends `reminder_confirm` once per pending
  submission when its round closes within 48 hours, with a fresh link
  (max 50 per round per run).
- **Eligibility** (`class-eligibility.php`, §5.5) via the
  `qc_validate_submission` / `qc_submission_flags` hooks:
  - `required_true` — e.g. "I live and work outside SEQ": blocks with the
    rule's own message.
  - `qhta_membership` — logged-in user with `pmpro_hasMembershipLevel()` → ✓;
    logged in without a level → ✗; logged out, institutional, or PMPro
    inactive → ? (manual). Flags unless ✓; stored in `_qc_member_check`.
    Guarded by `function_exists`.
  - `confirmer_differs` — referee email equal to the applicant's: flagged
    (or blocked if the rule is set to block).
  - **Entry limits** — always enforced when set: per entrant per category, per
    school per category, per entrant in total. Matching uses normalised
    entrant name + school (`_qc_norm_entrant`, `_qc_norm_school`: case,
    accents, spacing and punctuation ignored, so "Barcaldine S.S." =
    "barcaldine ss"). Withdrawn entries don't count. Real entries count real
    entries and test entries count test entries, so limits can be tried in
    preview.
- **Admin checks** box: membership override (✓/✗/?) that clears or resets the
  membership flag, and a "verified" tick for any field marked
  `"admin_verify": true` (the bursary's QCT number). All changes are logged.
  Submissions list gains a **Member?** column.
- `tests/m3-http-smoke.py` (referee flow incl. expiry, reuse, re-send, manual,
  kill switch; reminders; membership; same-email flag; entry limits; SEQ) and
  `tests/qc_http.py` (helpers shared with the M2 test).

### Changed
- Bursary preset: `qct_number` has `"admin_verify": true`.

### Notes
- The membership check accepts **any** active PMPro level. If QHTA has levels
  that shouldn't count (e.g. a free newsletter level), the check needs a list
  of member level IDs — tell me which.
- Locally, PMPro was replaced by a test stub (`pmpro_hasMembershipLevel()`
  backed by user meta). The member/non-member check against real PMPro is
  still needed on staging.
- Entry-limit matching is exact after normalisation: "Brisbane State High" and
  "Brisbane SHS" are different schools. A school dropdown would fix that if it
  matters.

## 0.2.0 — M2 (unreleased)

### Added
- **Front-end form** `[qhta_program_form program="slug|id"]`: server-rendered,
  progressive enhancement in plain ES5 (`assets/js/form.js`: show_if,
  label_if, category filtering by year level, inline/upload fields by category
  kind, live word counter). CSS scoped under `.qc-form`. Assets only on pages
  whose content has the shortcode (plus a render-time fallback). Form pages
  send `nocache_headers()`, `DONOTCACHEPAGE` and
  `X-LiteSpeed-Cache-Control: no-cache`. Logged-in users are prefilled from
  their account and PMPro billing fields (fields with a `prefill` hint).
- **Flag gating** (§5.11), checked in order: wpff inactive → nothing (admins get
  a notice) · `competitions` off → "temporarily unavailable" · round flag off →
  nothing · round in preview → form with PREVIEW banner, dates bypassed, saved
  as a test entry · round on → opens/closed messages per the round dates. The
  submit handler re-checks the same gate.
- **Test entries**: `_qc_is_test = 1`, ref `TEST-QC-yyyy-nnnn` from a separate
  sequence (real refs have no gaps), "[TEST]" email subjects. Submissions
  list: *Tests* and *Real entries* views, bulk **Delete test entries** (real
  entries in the selection are skipped). `Qhta_Competitions_Submission_CPT::real_entries_clause()`
  is the meta_query every limits/judging/export/outcome query must use.
- **Submit**: nonce, honeypot, signed render time (min 4 s, max 24 h);
  validation of required fields, emails, options, postcodes, category vs year
  level, inline word limits (block), declarations and typed-name signature.
  Errors re-render with values kept (files must be chosen again). Success →
  303 to a signed thank-you with the reference code.
- **Submission record**: `_qc_ref` (per-program atomic sequence, `QC-{close year}-0001`),
  `_qc_f_*` (only fields that applied — hidden show_if fields aren't stored;
  addresses as structured JSON), `_qc_category`, `_qc_submitter_email`,
  word counts + breakdown, `_qc_flags`, declarations snapshot (texts as shown,
  typed name, UTC time, IP, user agent), `_qc_publication_consent`. Status is
  `qc_pending_confirm` for referee rounds, else `qc_submitted`.
- **Protected uploads** (`class-files.php`): .docx/.pdf only, extension + finfo
  MIME + structure (PDF magic / docx zip with `word/document.xml`), size limit
  per round, random filename in `uploads/qhta-competitions/{program}/`.
  Download via `admin-post.php?action=qc_file` with nonce + capability check
  (admins; judges once M4 assigns them), served as `{ref}.{ext}`. Files are
  deleted with the submission.
- **Word count** (`class-wordcount.php`): inline count (unicode-safe), and .docx
  body + footnotes + endnotes (separators skipped), stopping at the first
  heading-styled or whole-paragraph-bold paragraph matching the round's
  exclude list. Tracked deletions, field codes and duplicate text-box fallbacks
  are not counted; tables and text boxes are. Upload counts never block: they
  flag a >10% mismatch with the declared count, or being outside the
  category's min/max.
- **Emails** (`class-mailer.php`): Mustache-lite (`{{token}}`, `{{#x}}…{{/x}}`,
  `{{^x}}…{{/x}}`), HTML template with navy header and gold rule
  (`templates/email.php`) plus a plain-text alternative. `submitted` to the
  submitter, `admin_new` to `qc_admin_recipients` (default: site admin email).
  Every send is logged on the submission.
- **Admin submission screen**: entry details (PII fields marked), word counts
  with breakdown, flags, file download, declarations as a signature block,
  history, and a status box (state-diagram moves, or an override with a
  required note). List columns: ref, entrant, school, category, words, flags,
  status; filters by round, category, flagged.
- Templates (`form.php`, `email.php`) can be overridden by copies in
  `uploads/qhta-competitions/templates/`.
- Tests: `tests/m2-http-smoke.py` (flag matrix, submissions, uploads, downloads,
  emails over HTTP), `tests/fixtures/make-fixtures.py` + three .docx fixtures
  with `expected.json`.

### Fixed
- Admin save: the anonymise, word-count-rule and award checkboxes were treated
  as unticked when their box wasn't in the POST at all, so a partial save could
  switch footnote counting off and clear the bibliography headings. Each box
  now posts a `_present` marker and is only applied when present.

### Assumptions / notes
- Field hints added to the presets: `role` (`entrant_name`, `school`,
  `submitter_email` — used by list columns, emails and, in M3, entry limits)
  and `prefill` (`name`, `email`, `phone`, `address`).
- `richtext_limited` is a plain-text box (no formatting); paragraphs are kept.
- The bursary "submitted" email says the referee has been emailed; the referee
  email itself is M3 (hooked on `qc_submission_created`).
- Entry limits, eligibility rules, the QCT tick and membership check are M3;
  `qc_validate_submission` / `qc_submission_flags` filters are where they plug in.
- Exports are M5; test entries are already excluded by `real_entries_clause()`.
- The .docx fixtures are generated, so their counts are exact by construction.
  The "within ±2 of Word" check still needs the three files opened in Word.

## 0.1.0 — M1 (unreleased)

### Added
- Plugin skeleton: `qhta-competitions.php` (header + `QC_VERSION`), loader,
  activation (admin `qc_manage` cap, `qhta_judge` role with `read` + `qc_judge`,
  `{prefix}qc_scores` table via dbDelta with `qc_db_version`, protected upload
  dir with `.htaccess` deny-all + `index.php`). Opt-in-only `uninstall.php`.
- `qhta_program` CPT (non-public, no REST, all caps → `qc_manage`) with the §3.1
  config stored as JSON post meta, validated on save; unknown keys inside
  config objects are preserved so config round-trips without loss.
- `qhta_submission` CPT with the ten `qc_*` statuses, the §6 state diagram
  enforced by `Qhta_Competitions_Submission_CPT::transition()` (admin override
  requires a note), and every status change written to `_qc_log`.
- Program admin screen: round settings (type, open/close as date + time in the
  site time zone, grace, anonymise, max upload, retention), JSON editors with
  in-place error recovery, confirmer, entry limits, word-count rules, privacy
  notice, bursary award. Programs list columns: type, flag badge, opens, closes.
- *New from preset*: `presets/ecc-bursary-2027.json` (real round) and
  `presets/writing-competition-2026-demo.json` (demo).
- wordpress-feature-flags integration (`class-flags.php`, the only caller of
  `wpff_*`): `competitions` kill switch (default on) and `comp-{slug}` per
  round (default preview), both admin-toggleable; keys assigned once at first
  save, suffixed on clash with registered flags, wpff UI flags or other rounds;
  registration reads the `qc_round_flags` cache (no queries inside
  `wpff_register`); trashed rounds stay registered until permanently deleted.
  Edit-screen box shows key, mode + source, live status, Tools link and secret
  preview link. Visibility gate (`round_gate()`) written for M2.
- `tests/m1-smoke.php`, `scripts/check-version.sh`.

### Additions beyond the §3.1 meta table
- `_qc_privacy_notice` (§7 "configurable privacy notice") and
  `_qc_max_upload_mb` (§5.4 "max size configurable") stored per round.
- Field schema `label_if: {field, equals, label}` — used by the bursary's
  "not yet in post for 2027" toggle to relabel the referee fields (§4.1).
- Confirmer config also carries `name_field` and `declaration` (the referee's
  declaration text).
- Declarations may carry `"kind": "publication_consent"` so the consent
  register can find that declaration.
- A text field keyed `word_count_declared` is the declared word count.

### Assumptions
- **Q12 (ECC free registration) is still open.** The award config carries
  `coupon_enabled: false` (default off), product 4311, no variation. The coupon
  itself is built in M5 behind that toggle; with it off the award email will
  say QHTA will arrange registration.
- The source PDFs were not available when the presets were written. Declaration
  text, bursary T&Cs and the five written-piece guidance points are drafted
  from the spec and **must be checked against the PDFs** before the bursary
  opens. Each preset's `_note` says so.
- Bursary preset opens 15 Dec 2026 09:00 (spec target "live by mid-Dec") and
  closes 29 Jan 2027 00:00 AEST; both are editable defaults.
- Writing competition demo: dates, word limits, entry limits (1 per student per
  category) and rubric are placeholders. *First Nations* is open to Years 7–12.
- Rubrics in both presets are placeholders.
- `CHANGELOG.md` lives in `docs/` (house rule: only README/AGENTS/CLAUDE at the
  repo root) rather than at the root as drawn in spec §6.

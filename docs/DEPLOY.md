# Deploy & live smoke test

Runbook for M6: staging first, then live. The bursary has to be live on the
ECC page well before applications are promoted. It closes **29 Jan 2027**;
the target is live by **mid-Dec 2026**.

Each step has a tick-box. Items marked **(carried)** are milestone checks
that could only be done on a real server; they were noted at M2–M5.

---

## 0. Before you start

- [ ] `./scripts/build-zip.sh` → `qhta-competitions-<version>.zip`. It
      checks the version guard, PHP lint, preset JSON and the script syntax.
- [ ] **wordpress-feature-flags 1.0.0** is installed on the target as the
      folder `wp-content/plugins/wordpress-feature-flags/`. The repo is
      `wordpress-feature-flag` (singular), so check the folder name.
- [ ] `wp-config.php` has `define( 'WPFF_PREVIEW_TOKEN', '…' );` (12+
      random characters) so the committee can preview with a secret link.
      This goes in the server's wp-config only, never in a tracked file.
- [ ] Settings → General → Timezone is **Brisbane**.
- [ ] You've decided on the **open items** at the end of this document.

---

## 1. Staging

### Install

- [ ] Plugins → Add New → Upload Plugin → the zip → Activate. WordPress
      refuses to activate without wordpress-feature-flags; that's intended.
- [ ] Purge LiteSpeed / hPanel cache.

### Health (Competitions → Health)

- [ ] **Uploads protected** ✓. Also check by hand
      **(carried, M2)**: `curl -I https://<staging>/wp-content/uploads/qhta-competitions/index.php`
      must return **403**. A 200 means LiteSpeed is ignoring `.htaccess`. Stop
      and fix that before taking real entries.
- [ ] **Scheduled tasks** ✓ within an hour of activating. Reminders,
      retention and outcome batches depend on it. If it stays ⚠, check
      Hostinger's cron or `DISABLE_WP_CRON`.
- [ ] **Mail test** → arrives through SureMail **(carried, M2)**, and isn't
      in spam.

### Bursary round

- [ ] Competitions → **New from preset** → *ECC Bursary 2027*.
- [ ] Check every box against the PDF (see `docs/PRIVACY.md`):
  - T&Cs and declarations;
  - the five guidance points under the written piece;
  - the referee declaration;
  - the privacy notice;
  - the email templates;
  - **Opens** (preset: 15 Dec 2026 09:00) and **Closes** (29 Jan 2027
    00:00 = "before the 29th").
- [ ] Publish. The *Feature flag* box shows `comp-ecc-bursary-2027` in
      **preview**.

### Putting the form on the ECC page

The round's flag gates the form itself: in preview the public sees nothing
where the shortcode is. So **don't tag the whole ECC page** with the round's
flag. That would hide the conference page from everyone until the round goes
on. Instead:

- [ ] Add a section to the ECC page (heading "ECC Bursary — apply", the
      shortcode from the round's *Shortcode* box, and any intro text).
- [ ] Wrap that section in wpff's **Feature flag block** (or Elementor
      container condition) set to `comp-ecc-bursary-2027`. The heading and
      intro then appear only when the form does. A dedicated page tagged with
      the flag also works.
- [ ] Logged out: the ECC page looks exactly as before.

### Staging tests (all in preview, so entries are TEST-)

- [ ] As admin: PREVIEW banner shows; submit an application with **your own
      email as the referee**. You get the `[TEST]` confirmation and the
      referee link.
- [ ] Open the referee link in a private window: the summary shows no
      contact details. Confirm → status *Submitted*, and the applicant
      `[TEST]` email arrives.
- [ ] Secret link: Tools → Feature flags (or the round's flag box) → copy
      the preview URL. Open it logged out; the form appears with the banner.
- [ ] **PMPro (carried, M3):** log in as a real member and submit → Member?
      ✓. Log in as a non-member → ✗. Remember the per-entrant limit is 1; use
      different names, or delete the test entries between tries.
- [ ] **Which PMPro levels count (open item):** if a free or newsletter level
      shows ✓, give me the qualifying level IDs.
- [ ] **Judge access (carried, M4):** test entries never go to judges, so
      for this check set the round **on** briefly and make one real entry.
      Add a subscriber/customer account as a judge (Competitions → Judges),
      put them on the bursary panel, and *Send to judging* that entry. The
      judge logs in → lands on **Judging** (not WooCommerce's My Account) →
      sees the reference only → can score. Then remove the judge role and
      check their own role and PMPro membership are unchanged. Withdraw the
      entry and set the round back to preview.
- [ ] **Exports (carried, M5):** Competitions → Exports → open each CSV in
      Excel. Accents and apostrophes display correctly, and no "repair"
      prompt.
- [ ] **Word count (carried, M2):** in the demo writing-competition round,
      upload the three files in `tests/fixtures/` (in the repo, not the zip).
      The admin's "Counted" figure should be within ±2 of Word's own count of
      the same part. Word counts everything, so select up to the Bibliography
      or References heading and include footnotes and endnotes.
- [ ] Clean up: Submissions → **Tests** → select all → **Delete test
      entries**. Withdraw or trash the real staging entries.

---

## 2. Live

- [ ] Back up the database (hPanel).
- [ ] Upload and activate the same zip. Purge the cache.
- [ ] **Health:** uploads ✓ (plus the `curl -I` 403 check), cron ✓ (check
      again an hour later), mail test arrives.
- [ ] Recreate the bursary round from the preset with the same edits as
      staging (rounds don't migrate between sites). Publish → preview.
- [ ] Add the flag-wrapped section to the live ECC page as on staging.
      Logged out, the page is unchanged.
- [ ] Committee preview: send them the secret preview link. They can submit
      TEST entries; delete those before going on.
- [ ] **Go live:** Tools → Feature flags → `comp-ecc-bursary-2027` → **on**.
      Before 15 Dec the section says "Submissions open …"; from 15 Dec the
      form shows.

### Live smoke test (spec M6 done-check)

Once the round is open (on, and after *Opens*):

- [ ] Logged out, on the live ECC page: submit **one real application**.
      Use your own email and a second address you control as the referee,
      and an obviously-test name such as "Smoke Test — please ignore".
- [ ] The confirmation email arrives with a `QC-2027-…` reference (no
      `[TEST]`). The referee email arrives.
- [ ] Confirm through the referee link → *Submitted*, and the applicant gets
      the "confirmed" email.
- [ ] Admin: the submission screen shows the signature block, referee box,
      Member? and flags. Competitions → Exports → the entry is in the CSV.
- [ ] **Withdraw it:** submission screen → Status → *Withdrawn*, note "Live
      smoke test". Withdrawn entries don't count towards limits and never
      reach judges or outcome emails.
- [ ] Only now: promote the bursary (newsletter, social, ECC emails).

---

## 3. Rollback

- **Hide one round:** set its flag **off**. The form disappears; existing
  entries and referee links keep working.
- **Pause everything:** set `competitions` **off**. Every form shows
  "temporarily unavailable" and referee links pause (they stay valid).
- **Remove the plugin:** deactivating keeps all data. Uninstalling deletes
  data only if `qc_delete_data_on_uninstall` was set to 1 first.

---

## 4. During and after the round

| When | Do |
|---|---|
| While open | Watch Submissions for ⚑ flags and Member? ?/✗. Tick *QCT verified* as you check. Re-send or manually confirm stuck referee links. |
| 48 h before close | Referees still pending are reminded automatically (once). |
| After close | Select entries → **Send to judging**. Set up the panel. |
| Judging | Competitions → **Results**: shortlist, then award / commend / unsuccessful. |
| Outcomes | Submissions → **Notify outcome** → check previews → send yourself a test → send. Batches of 20/minute. |
| Award | Free registration: create the complimentary order by hand. Travel reimbursement is offline. |
| Afterwards | Exports → consent register for publication. Retention purges unsuccessful entries' personal details 12 months after close; Competitions → **Retention** shows the date and the list. |

---

## Open items

| # | Item | Needed by |
|---|---|---|
| Ref | Reference codes repeat across rounds closing in the same year (`QC-2027-0001` in two rounds). Options: per-round prefix (recommended), one global sequence, or leave as spec. | Before a second 2027 round opens |
| PMPro | Which membership levels count as "QHTA member" (currently any active level). | Before the bursary opens |
| Wording | Bursary T&Cs, declarations and guidance checked against the PDF. | Before the bursary opens |
| Schools | Entry-limit and conflict-of-interest matching uses typed school names ("Brisbane SHS" ≠ "Brisbane State High"). A school dropdown would fix it if it matters. | Writing competition |

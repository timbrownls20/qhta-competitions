# Healthcheck note — keep qhta-competitions' canaries current

**Standing rule.** When this plugin is created or changed in a way that adds or
alters an external dependency (a wordpress-feature-flags function, a PMPro or
WooCommerce function or filter, a cron event, a DB table or column, the
protected uploads folder, a wp-config constant), update its
**qhta-healthcheck** canaries in the *same* change.

The canaries live **in this repository**, in `includes/class-healthcheck.php`,
registered on qhta-healthcheck's `qhta_healthcheck_checks` filter under the
slug `qhta-competitions`. Changing the dependency and changing its canary are
the same diff, reviewed and deployed together. There is no central copy:
`qhta-healthcheck/includes/checks.php` deliberately holds none.

A new dependency with no canary is the silent-failure risk qhta-healthcheck
exists to catch.

## How it behaves

- Nothing runs unless **qhta-healthcheck** is installed. `add_filter()` on a
  hook nobody applies costs nothing, so the class is inert without it.
- The assertion helpers (`qhta_healthcheck_*`) belong to qhta-healthcheck and
  are only called from inside its runner, which wraps every check in a
  try/catch. An older qhta-healthcheck missing a helper shows as a failed
  check, never a fatal.
- Every canary is **read-only**. Repairs (recreating the upload folder's
  `.htaccess`, sending a test email) stay on **Competitions → Health**, which
  is the operator's fuller self-test.
- Until a release containing `includes/class-healthcheck.php` is deployed,
  qhta-healthcheck shows the plugin amber — *"no canaries defined"*. That's
  expected, not a bug.

## This plugin's canaries (14)

**R** = remote (HTTP; runs on the daily pass or *Run checks now* only).

| Canary | Sev | Watches |
|---|---|---|
| `feature-flags-api` | critical | `wpff_register`, `wpff_flag`, `wpff_mode`, `wpff_is_live`, `wpff_flags`, `wpff_preview_url`. Without them every form fails closed (hidden). |
| `module-flag-on` | warning | The `competitions` kill switch is **on**. Off = every form says "temporarily unavailable". |
| `round-flags-registered` | warning | Every published round's `comp-{slug}` flag is registered with wpff. Skipped with no published rounds. |
| `form-shortcode` | critical | `[qhta_program_form]` is registered. |
| `upload-protection-files` | critical | `uploads/qhta-competitions/` has its deny-all `.htaccess` and `index.php`. |
| `upload-protection-http` | critical, **R** | A request for `uploads/qhta-competitions/index.php` is refused (401/403/404). Catches LiteSpeed ignoring `.htaccess`. Honours the `qc_health_probe_url` filter. |
| `cron-scheduled` | critical | `qc_cron` is scheduled. Lateness is left to the next canary. |
| `cron-heartbeat` | warning | `qc_cron_last` is under 3 hours old: the hourly task really runs (reminders, retention, outcome safety net). |
| `scores-table` | critical | `{prefix}qc_scores` exists with the columns the code writes, and `qc_db_version` matches `DB_VERSION`. |
| `outcome-queue` | warning | No outcome emails waiting without a `qc_outcome_batch` scheduled. |
| `judge-admin-access` | warning | `Qhta_Competitions_Judging::allow_judge_admin` still on `woocommerce_prevent_admin_access`, `pmpro_block_dashboard` and `woocommerce_disable_admin_bar`. |
| `preview-token` | warning | `WPFF_PREVIEW_TOKEN` is defined and 12+ characters. The value is never shown. |
| `site-timezone` | warning | Site time zone is `Australia/Brisbane`, so round dates mean what they say. |
| `pmpro-membership-api` | warning | `pmpro_hasMembershipLevel()` exists. Skipped when PMPro isn't active (membership is then checked by hand). Fails if PMPro is active without it. |

## More

- Full rule, rationale and the other plugins' canaries:
  `qhta-healthcheck/qhta-healthcheck-handover.md`.
- qhta-healthcheck covers internal correctness only. Site-up liveness is the
  external "QHTA site guardian" HTTP task. Keep both.

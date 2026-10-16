# Joomla 6.2 compatibility review

Validated on 2026-10-16 against Joomla 6.2.0, PHP 8.3 and MariaDB 10.6.

## Result

The existing project passes its complete CI suite on Joomla 6.2.0. Browser testing
found one email-link issue that requires the accompanying composer fix before
recommending production use of the new link picker. The complete suite also
passes with that fix. No further InterCOM conflict was identified in the 6.1.4
or 6.2.0 changes reviewed.

Joomla 6.2's TinyMCE link picker returns local media links such as
`images/joomla_black.png`. Before the fix, saving a picked link left its text
but removed its destination: InterCOM deliberately accepts only absolute HTTP,
HTTPS and mailto links in email HTML. The editor now uses `relative_urls=false`,
`remove_script_host=false` and the site's root URL as `document_base_url` for
both body languages. This makes TinyMCE serialize picked links as absolute URLs
without loosening server sanitisation. It also covers manually entered local
links; already-saved links whose destinations were removed need reinserting.

The browser reproduction selected Joomla's bundled `joomla_black.png` through
**Insert/edit link → Browse links → Media**, saved the draft, and inspected both
the database content and the email preview. The fixed draft and preview retained
`http://localhost:28088/images/joomla_black.png`.

A delegated Manager account, with InterCOM composition access and no component
administration permission, saved English and Danish HTML containing bold,
italic, underline, a list and absolute links. All five features survived in each
saved body. Danish characters and the dark preview also rendered correctly.
This checks Joomla's default text-filter configuration; production-specific
filter customisations still need a staging check.

## Automated verification

The ordinary `scripts/ci.sh` was copied to a temporary runner which invoked
`php cli/joomla.php core:update --no-interaction` immediately after each call to
`scripts/stack.sh up`, and printed the resulting core version. Both disposable
installations reported Joomla 6.2.0. The official 6.2.0 PHP 8.3 Docker image tag
was unavailable at test time, so this exercised native core upgrades from the
repository's pinned 6.1.3 image instead. The repository's default CI image is
still 6.1.3 and should gain regular 6.2 coverage separately.

Both the original source and the fixed source completed the suite, including:

- Composer validation, lint, style, 152 PHP unit tests / 678 assertions,
  tooling tests and 21 frontend tests.
- Fresh package installation with compatibility plugins disabled.
- Synthetic migrations and the checksum-pinned public 0.3.11 → 0.3.23 upgrade,
  including decryptable credential, content, setting and permission preservation.
- Native Database maintenance and Update Structure with no InterCOM problems.
- Real Joomla editor rendering, with regression checks for both language options.
- Native scheduler dispatch, failure cleanup, renewal and reconciliation tests.
- HTTP login, CSRF, configuration-save validation and permission boundaries.
- Test delivery through Joomla's mailer into disposable Mailpit, with no member send.

## Persistent local development site

The running site was Joomla 6.1.3 with InterCOM 0.3.11. Joomla's native updater
successfully upgraded it directly to 6.2.0. This was not a runtime test starting
from production's 6.1.4; the 6.1.4 changes were additionally reviewed in source.

A private filesystem archive and database dump were taken before the upgrade.
The site secret, InterCOM settings and installed extension manifest remained
unchanged. Thirteen of fourteen InterCOM tables retained identical row counts
and fingerprints; the audit table gained two normal maintenance/reconciliation
completion events. The existing scheduler ran during the observation window.

Joomla's native database checker reports a warning for installed InterCOM 0.3.11:
`Table 'ic_intercom_tags' does not have column 'IF'. (From file 0.3.1.sql.)`
The current package resolves this in the isolated public-upgrade test. The
persistent site's InterCOM installation was deliberately left at 0.3.11; neither
the current package nor the unreleased link fix has been installed there.

The persistent site uses live CleverReach mode, so mutating test fixtures and
browser saves ran only on disposable fake-provider sites. No member mailing was
triggered by these tests. Production was not modified. The fix is included in
the InterCOM 0.3.24 release preparation; deploy that package before using the
new link picker in production.

## Sources

- [Joomla 6.1.4 milestone](https://github.com/joomla/joomla-cms/milestone/168?closed=1)
- [Exact 6.1.3 → 6.1.4 changes](https://github.com/joomla/joomla-cms/compare/6.1.3...6.1.4)
- [Joomla 6.2.0 release](https://github.com/joomla/joomla-cms/releases/tag/6.2.0)
- [Exact 6.1.4 → 6.2.0 changes](https://github.com/joomla/joomla-cms/compare/6.1.4...6.2.0)
- [Native TinyMCE link picker](https://github.com/joomla/joomla-cms/pull/48138)
- [TinyMCE text-filter default](https://github.com/joomla/joomla-cms/pull/48041)

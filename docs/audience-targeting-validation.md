# Audience targeting: production pre-deployment validation

Validated on 8 October 2026, after the owner confirmed Sixte's release and sync. InterCOM has not been deployed or published as part of this validation.

## Production tag readiness

CleverReach Members list **609312** contained 313 mailboxes, of which 299 were active. All 313 mailboxes carried at least one `member.*` tag, with 119 distinct member-tag variants present. The new discipline tags were found on receivers: `discipline.epee` on 6 mailboxes, `discipline.foil` on 5 and `discipline.sabre` on 7. No receiver carried `group.epee`, `group.foil` or `group.sabre`.

This confirms the tag rollout prerequisite on the actual production list, rather than merely observing account-wide tag definitions.

## Live rule checks

The test used the workspace's new `Audience::validate()`, `CleverReachGateway::filterRules()`, `updateAudience()`, `assertAudience()` and `statistics()` against a newly created temporary segment. Each test retained the ordinary `club` suppression rule. Training groups were all currently present tags starting with `group.Adult_`; discipline selection was foil or sabre.

For each case, an independent comparison evaluated the production receiver snapshot's whole tags and suppression values. CleverReach's active count and its complete active receiver-ID set both matched that independent result. Only aggregate counts were printed or recorded; addresses, receiver IDs and credentials were not recorded.

| Audience | Expected active | CleverReach active | Saved-rule read-back and recipient-set comparison |
| --- | ---: | ---: | --- |
| Ages 6-9 | 32 | 32 | Passed |
| Ages 8-12 | 47 | 47 | Passed |
| Women, all ages | 117 | 117 | Passed |
| Girls aged 12-14 | 5 | 5 | Passed |
| Ages 18 and over | 208 | 208 | Passed |
| Ages 9 and under | 35 | 35 | Passed |
| No age, gender, group or discipline selection | 299 | 299 | Passed |
| Ages 0-120: full 363-tag, 5,840-character rule | 299 | 299 | Passed |
| Sabre only | 7 | 7 | Passed |
| Adult training groups only | 66 | 66 | Passed |
| Foil or sabre only | 11 | 11 | Passed |
| Any adult training group AND either foil or sabre | 7 | 7 | Passed |

The combined selection therefore behaves as approved by the owner: **any selected training group AND any selected discipline**. It does not include all 66 adult-group mailboxes or all 11 foil/sabre mailboxes.

The temporary segment was **786725**, named `InterCOM-predeploy-20261008-120254-425cb343`. It was deleted after the checks, and the subsequent segment catalogue confirmed it was absent. Existing segments were not changed. No receiver tags or attributes were written, no mailing was created, and no email was sent.

## Local validation and deployment boundary

The previous full local CI passed, including installer migrations, authenticated HTTP and permissions, scheduler and database maintenance, fresh installation, and synthetic and checksum-verified public-package upgrades. The final local unit suite passed 147 PHP tests; 21 frontend tests passed. The public-package upgrade test verifies that saved discipline/age audiences, catalogue settings and audience-access grants migrate and require a fresh test.

Production rollout readiness and live filtering are now verified. Deployment still follows the repository's release procedure: review and commit the implementation on `main`, synchronize it with origin, then prepare 0.3.20 using `docs/releases/0.3.20.md`. Publication and production installation were not requested in this test run. After installation, refresh recipient tags, review migrated saved audiences, and obtain a new estimate and send test before releasing an affected draft. These filter checks do not prove inbox receipt or production installation.

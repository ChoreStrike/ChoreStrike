# Installation Candidate Qualification (2026-10-08)

**2026-10-09 correction:** the unchanged owner-layout v2 archive failed the
operator's production functional smoke: its lazy runtime requested `/apps`
assets while the installation served them from `/custom_apps`. Earlier owner,
style and hash measurements remain valid in their aliased test layout, not an
alias-free deployment proof. The app-side follow-up is recorded under
[Alias-Free Follow-Up](#alias-free-follow-up-2026-10-09); the final source gates
and separate package smokes pass. No production update or baseline adoption is
authorized by this work.

Historical 2026-10-08 status: the owner-layout/map-free follow-up was qualified on a fingerprinted
working tree based on `6a5c6e75b00bbb02e899a2a86a531cfba7c38beb`, before its
source commit. Commit/push were separately authorized on 2026-10-08; source
publication does not rebuild or publish the local binary archive.
The [owner-layout follow-up](#owner-layout-follow-up) superseded the original
archive below at that time. The original mobile smoke did not require the owner
controls; it did not cover the subsequently reproduced overflow. The explicitly
retained dependency and native core-platform limitations still apply. This is not a
deployment, production security clearance, release sign-off or retained-baseline
adoption. WP9 comprehension and 14-day everyday-use evidence remain open.

## Source And Artifact Identity

This section records the original qualification, not the follow-up build.

- Starting branch: `master`, clean at `69af07f653c46383482c211f5cd70f600fbe9322`.
- Compared with `d49d2db51a5b1c2373ed480450271b01edb7eb29`, the starting commit
  changed only twelve documentation files. No reset or branch switch was used.
- App version remains `0.1.1`; declared NC34-35 / PHP8.4 compatibility is unchanged.
- Qualification ran on the explicitly fingerprinted working-tree candidate
  before its source commit, not on a clean-HEAD checkout. Commit and push were
  separately authorized on 2026-10-08 after qualification; the source commit
  carrying this record does not rebuild or publish the local binary archive.
- Source manifest: 757 build/runtime/test/configuration files, including the new
  regression test (untracked during qualification). SHA-256 of its sorted compact JSON path/hash list:
  `28279e30232699a482c23ffc2f53aa3e9c47c67fe412c93ec33115a03a4f9fef`.
- `package-lock.json` SHA-256:
  `76754f7fadddedbc4464bfff73c6f64453909c8ebb44d943e3badbf182303f6d`.
- Production webpack compilation: `5.105.2`, build hash `8effecc7434717b4f748`,
  50 output files including chunks, maps and licence notices; zero build warnings/errors.
- Frozen archive: `chorestrike-0.1.1-install-prep-20261008.tar.gz`, SHA-256
  `0d930fca96feb8bfa2c2c450fd1cb7c29caba9451c44ffbfe4fc83f283492034`.
- Archive contains 250 files. Its sorted compact JSON file/hash manifest has
  SHA-256 `3edb480fc8d57ae112eb938b87d4b00618b172c21d6083ac216e1a80c3e296c6`.
  Extracted files were compared byte-for-byte by hash with that manifest.
  Archive metadata uses numeric owner/group 0, directories 0755 and files 0644;
  Windows-generated world-writable metadata was rejected before qualification.

Source manifests intentionally exclude documentation and generated assets;
the separate package manifest covers the actual generated/shipped bytes.
Local-only audit responses, advisory-by-advisory disposition, module statistics,
commands, timestamps, hashes and browser reports accompany the handoff. Do not
publish raw reports, traces, cookies, synthetic account state or host paths.

## Asset Contract

The shared webpack configuration uses `style-loader`. The main script injects
global styles; route/vendor lazy scripts inject their own global/scoped styles.
There is no standalone `css/chorestrike-main.css`.

The repair removes both PHP registrations, in `templates/main.php` and
`PageController::index()`, and the ineffective `MiniCssExtractPlugin` block and
direct dependency. It neither changes to CSS extraction nor ships a placeholder.
The contrary historical assumption in CS-PLAN-069 is explicitly corrected; the
durable contract is in [deployment.md](../engineering/deployment.md#2-build-the-frontend).

New unit coverage loads the real webpack configuration outside Jest's Nextcloud
mocks and checks both PHP entry points. Required Chromium scenarios at 1440x900
and 390x844 verify real navigation, main/lazy chunk loading, applied global and
Vue scoped styles, absence of the stylesheet link, resource/HTTP/console/page
errors and CSP violations. They inspect the actual app/view scroll containers
and representative control bounds, not just document width.

This stronger test reproduced narrow-screen overflow in notification/pause
form rows. The only UI fix is `flex-wrap: wrap` on those two existing field
classes; no labels, preference behavior or business logic changed.

The full gate also exposed an existing retention-test defect, not data loss:
the API returns `{ floor, rooms, stats }`, and room query order is unspecified.
The reset snapshot now reads and asserts the actual floor fields and compares
retained room fields by stable ID. No timeout increase, retry, skipped assertion,
production query change or reset semantics change was used.

## Dependency Disposition

Registry/audit queries were refreshed on 2026-10-08. Counts describe affected
package nodes, including inherited advisory chains, not distinct exploitable
browser vulnerabilities. Neither audit mode describes the files in the release
archive: `dependencies` also contains tooling, and no `node_modules` is shipped.

| Audit | Before (low/moderate/high) | Candidate (low/moderate/high) |
|---|---|---|
| Full | 84 (7/14/63) | 62 (5/13/44) |
| `--omit=dev` | 20 (5/4/11) | 5 (5/0/0) |

Targeted updates use existing compatible stable ranges. The only added override
floors are same-major `fast-uri@3 >=3.1.8`, `brace-expansion@1 >=1.1.21` and
`minimatch@9 >=9.0.9`; these affect separately resolved source packages, not code
already embedded in another distribution. No `npm audit fix`, ignored engines,
legacy peer resolution, dependency-category relabelling or speculative major
upgrade was used.

| Relevant Browser Package | Replacement And Observed Use |
|---|---|
| Axios 1.13.5 -> 1.20.0 | Emitted in the main bundle via `@nextcloud/axios` 2.5.2. `src/main.js` and `src/services/api.js` use it for app-relative API requests. Browser mapping excludes the Node HTTP adapter; common/browser advisories were still addressed by updating the leaf. |
| DOMPurify 3.3.1 -> 3.4.16 | Emitted in the main bundle. Used by `@nextcloud/l10n` translation sanitization and Nextcloud Vue's SVG-string branch. Observed app inputs include translation strings/escaped placeholders; that is not a blanket safety exemption. |
| WebDAV 5.9.0 -> 5.11.0 | Replaces the actual prebuilt browser implementation emitted in an asynchronous dialogs/files chunk. The app uses toast helpers, not the file-picker/DAV APIs; no complete app attack chain was established. |

The replaced WebDAV browser file has SHA-256
`9a0ee3be8fa57402d2c5d3f57d9be1326adefac28a30e8f5d9d31335f693e2ce`.
The release-source lock at upstream `gitHead`
`ab039cb8d793d03a6b1c15c80b650d9e9d727679` identifies parser 5.11.1 and minimatch
9.0.9 build inputs. That is provenance, not independent version attestation of
every embedded implementation. The app lock's parser 5.11.2 cannot establish the
contents of a prebuilt file. Merely overriding child packages was not the fix.

Other targeted lock updates cover supported compiler/parser/minifier branches,
Vue 3.5.43 and associated runtime leaves. The private disposition matrix maps
every direct advisory from the initial audit to before/after versions, advisory
ID, final audit status and emitted-module evidence.

### Explicitly Open Groups

On 2026-10-08 the user chose **bounded deferral**, not production-risk acceptance.
These six advisory roots remain open under [RELEASE-002](../backlog/backlog.md).
The final webpack chunk inventory contains no standalone modules for these
roots, nor the elliptic parent polyfill chain. This does not independently inspect
all internals of third-party prebundles or make future dependency graphs safe.

| Remaining Root | Advisory | Bounded Reason / Recheck Condition |
|---|---|---|
| `braces` 3.0.3 | GHSA-vfj7-8cjw-p6xm (high) | Local build/test glob chain; no corrected stable compatible release reported. Use trusted source/patterns in an isolated build without production credentials; recheck upstream fixes. |
| `elliptic` 6.6.1 | GHSA-848j-6mx2-7j84 (low) | Installed through `node-polyfill-webpack-plugin` 4.0.0. No corresponding emitted crypto modules or app API use observed. Shared webpack config 6.3.2 pins the plugin peer exactly to 4.0.0; changing to the 4.1 replacement needs a separately tested peer/toolchain migration. Reassess if browser crypto usage changes. |
| `fast-xml-parser` 4.5.7 | GHSA-gh4j-gqv2-49f6 (moderate) | The installed Nextcloud ESLint plugin calls `XMLParser.parse()` on local `appinfo/info.xml`, not the affected `XMLBuilder` API. This is not WebDAV's replaced browser parser. A corrected >=5.7.0 package is outside the parent's 4.x contract; recheck usage/parent migration rather than declare the installed package fixed. |
| `postcss-selector-parser` 6.1.4 | GHSA-rj75-hqrm-r3gf (moderate) | Vue ESLint 9.x source-selector processing. The build/stylelint 7.1.6 branch is updated; the lint parent's remaining 6.x branch needs separate compatibility work. |
| `sprintf-js` 1.0.3 | GHSA-hp3w-g68c-fv3c (moderate) | Jest 29's installed config-tool chain includes argparse's `sprintf()` help/usage formatter. Some format arguments are dynamic; do not claim they are all literals or accept untrusted format/config input. All published stable releases through latest 1.1.3 were affected; recheck publication. |
| `uuid` 8.3.2 | GHSA-w5hq-g745-h8pq (moderate) | Installed SockJS calls only argument-less `v4()`, not the advisory's buffer-taking v3/v5/v6 path. No Node/dev server belongs in the package or production. Keep the advisory open and reconsider if call sites or the parent version contract change. |

Newer Nextcloud Vue/dialogs/files lines are not silently adopted: transitive
Vue Select/Files engine requirements conflict with Node 20.19.0. No engine
exception or untested major override is substituted for that migration.

## Executed Verification

Frontend commands used Node **20.19.0**, npm **10.8.2** (not the host's Node24/npm11).
The final lock passed `npm ci --engine-strict --legacy-peer-deps=false`; the
installed dependency tree passed `npm ls --all`. Final production compilation
uses `npm run build` with private webpack JSON statistics.
An optional-inclusive check of all 1,628 lock entries found no declared Node/npm
engine mismatch at those exact versions. A final handoff-time audit reproduced
the same counts and six advisory IDs.

| Check | Result |
|---|---|
| Jest / `npm test` via `npm run test:noise` | 114 suites, 1,468 tests pass; no error-level application log noise |
| `npm run lint` | Pass |
| `npm run stylelint` | Pass, with the existing 26 `color-function-alias-notation` warnings tracked by TECH-LINT-007; no new warning exemption |
| PHP CS check, without cache | Pass |
| Composer validation | Pass |
| l10n source/catalog check | Pass; no user-visible string changed |
| Documentation encoding, backlog ownership and changed-document links | Pass; 169 local links checked, including this new handoff document; advisory IDs and artifact hashes reconciled with raw local evidence |
| Browser-gate selection/failure self-test | Pass; both new asset cases are mandatory |
| NC34 backend unit / PostgreSQL integration | 1,367 tests / 603 tests pass, `--fail-on-skipped` |
| NC35 backend unit / PostgreSQL integration | 1,367 tests / 603 tests pass, `--fail-on-skipped` |
| Corrected retention scenario | Three no-retry repetitions pass on each runtime row |
| Final NC34 focused asset smoke | Eight tests including setup pass, no retries |
| Final NC34 full consecutive browser pair | `run-X1Q29H` + `run-xhZQwU`, 290 first-attempt executions each, zero skips/failures/flakes |
| Final NC35 full consecutive browser pair | `run-qJq5WQ` + `run-FvG4KC`, 290 first-attempt executions each, zero skips/failures/flakes |
| Production Composer `install --no-dev` and generated autoloader | Pass in an isolated package on PHP8.4.25, network disabled; zero runtime/development packages, production-only app autoload |
| App `composer check-platform-reqs --no-dev` | Pass: PHP8.4.25 |
| Extracted-archive read-only mounted browser smoke | Pass on NC34 and NC35 at 1440px/390px; global/scoped styles and navigation work, zero resource/console/page/CSP errors, no inner horizontal overflow |

Each full gate contains 87 Chromium, 164 serial-DB and 39 scheduling-owner
executions, including setup. Both runtime-specific pairs ran between 10:08 and
11:11 UTC with `workers=1`, `retries=0`, `--forbid-only` and mandatory scenario
inventory checks. Source/package manifests were rechecked unchanged afterwards.
The extracted-archive checks ran at 11:13 UTC with Chromium **145.0.7632.6**
(Playwright **1.58.2**); their screenshots and byte-for-byte post-check are local
evidence. No additional browser-engine qualification is claimed.

All new tests use separately owned synthetic projects `cs082-assets-20261008-nc34`
and `cs082-assets-20261008-nc35`, loopback-only HTTP 8185/8186, frozen images and
PostgreSQL **18.6**. No pre-existing database or volume was reset or removed.
Runtime rows are NC **34.0.4** (internal 34.0.4.1) and NC **35.0.1**, both PHP
**8.4.25**, as defined by `docker/test-runtime.json`. This renewal does not claim
a fresh MySQL/SQLite/preservation matrix; existing provider evidence remains
attached to its original source/runtime state.

Native NC34 root platform requirements pass. Its **unmodified** native
`3rdparty` check still exits **1**, solely for `lcobucci/clock`'s PHP constraint
`~8.1.0 || ~8.2.0` on PHP8.4.25. This is the previously documented, narrowly
identified upstream image condition, not a green platform check, new exception
or permission to bypass checks on a real installation. NC35 image verification
retains its own existing, separately identified upstream build conditions.

Historical `run-29nxkD` / `run-Wy1fPg` are **NC35** evidence, never NC34 browser
proof. Initial NC34 `run-n31X4R` passed; `run-QwghWI` failed the old room-order
snapshot. Later deliberately interrupted pairs and the mobile-overflow failure
are diagnostic evidence, not qualifying full passes. Only the four complete
final-source runs listed above form the renewed passing pairs.

## Packaging Handoff

The original archive above is historical and is superseded by the separately
hashed [map-free follow-up](#owner-layout-follow-up). The runtime allowlist is
`appinfo`, `lib`, `templates`, `l10n`, `img`, matching JavaScript bundles and lazy
chunks with their licence notices, licence, Composer manifest/lock and generated
production autoloader. Exclude source maps (`*.map`), `node_modules`, tests,
private evidence, Docker tooling and stale/empty CSS. The original archive
included 21 source maps and therefore did not meet this corrected package
contract; it is retained unchanged as historical evidence.

The archive was extracted and bound read-only into each owned test app after
the full pairs, retaining those projects' config/data/database volumes and
skipping dependency installation/reseeding. Installed runtime/provider identity
was reverified; the actual extracted app's native platform check passed. Archive
and extracted-file hashes remained unchanged after the browser checks. These
two task-owned stacks are retained on loopback 8185/8186 for diagnosis; canonical
and pre-existing stacks/volumes were not touched.

A new webpack invocation changes timestamp-based cache busting and therefore
creates a different artifact, even from identical source. A later build or
repack must be explicitly requalified and identified, never substituted silently.
Target-specific native Nextcloud checks, AIO packaging/runbook verification,
operator deployment decisions and any eventual retained-baseline adoption
remain subsequent work. No server connection, installation or adoption occurred.

## Owner Layout Follow-Up

### Correction And Coverage

The operations feedback reproduced owner-only Settings overflow at 390x844 on
the original runtime bytes. The new regression also fails against that original
archive at 390px while its 1440px case passes. The earlier default-account asset
smokes remain valid for their narrower role coverage, not proof of owner layout.

`Settings.vue` now wraps input groups, floor header rows and floor actions;
inputs/selects have a shrinkable, bounded width. Controls are neither hidden nor
clipped. No UI string, authorization, business logic, schema or dependency changed.
`settings.spec.ts` uses the real seeded house owner with populated floors and
rooms, positively asserts the owner section and all five floor actions, checks
actual container/control bounds at 390x844 and 1440x900, saves and reloads the
timezone, restores it, and opens/cancels the floor deletion confirmation. Both
cases are mandatory in the browser gate and its exact scenario inventory.

Two test-only defects exposed during verification were repaired rather than
suppressed. Provisioning fixtures now isolate inherited preservation/update
environment flags, with a regression for a preserved parent environment. The
due-date visibility integration test compares the persisted UTC instant in the
house timezone, explicitly covers UTC and Europe/Berlin, and restores the shared
house's original timezone in `finally`. No production persistence behavior changed.

### Follow-Up Identity

- Base commit: `6a5c6e75b00bbb02e899a2a86a531cfba7c38beb`; qualification preceded
  the separately authorized source commit/push. This is not a clean-HEAD rebuild
  or a published binary release.
- Final source manifest: 757 files; SHA-256
  `12da779ce0f0990f1a682d5e0524907249d63917c96ca8949b4d09ecc5c34f9c`.
- Webpack 5.105.2 build hash: `991c004a7776f382ef39`; zero warnings/errors.
- Runtime archive: `chorestrike-0.1.1-owner-layout-20261008-runtime-v2.tar.gz`.
- Archive SHA-256:
  `14ab05a4e73a89d85921c810656b5c91e818d9b7bcdb2dd17e710e64516dec75`.
- Package: 229 files, including exactly the 29 matching emitted JavaScript/
  licence assets; no source maps. Sorted compact JSON file/hash manifest SHA-256:
  `46a8700fc51b15f680d77478783c89e28cde752ae1ed470efdabc0df0f37957e`.
- Archive owner/group 0; files 0644, directories 0755. Extracted file hashes
  match the package manifest; source, archive and extracted bytes were rechecked
  after both package smokes. Earlier rejected packaging attempts were not
  substituted for this named `v2` archive.

### Follow-Up Verification

| Check | Result |
|---|---|
| Node20.19.0/npm10.8.2 production build | Pass, with unchanged dependency lock |
| Jest via `npm run test:noise` | 114 suites / 1,468 tests pass |
| ESLint, Stylelint, l10n consistency | Pass; no changed strings |
| Documentation encoding, backlog ownership and diff whitespace | Pass |
| PHP CS for both changed test files | Pass without cache |
| Browser selection/failure self-test and real inventory discovery | Pass; both owner-width cases required |
| NC34 backend unit / PostgreSQL integration | 1,368 / 604 tests pass, `--fail-on-skipped` |
| NC35 backend unit / PostgreSQL integration | 1,368 / 604 tests pass, `--fail-on-skipped` |
| NC34 full browser gate | `run-9h8HNm`: 292 first-attempt executions, zero skips/failures/flakes |
| NC35 full browser gate | `run-B3oUKu`: 292 first-attempt executions, zero skips/failures/flakes |
| Production Composer install, app platform check and production-only autoloader | Pass on PHP8.4.25 in a network-disabled package builder |
| Read-only extracted archive, explicit owner with populated floor | Pass on NC34 and NC35, each at 390px and 1440px |

Each browser run comprises 87 Chromium, 166 serial-DB and 39 scheduling-owner
executions, including setup, with one worker, zero retries and `--forbid-only`.
This follow-up ran **one full gate per runtime**, not a new consecutive pair.
The original paired evidence remains tied to its earlier candidate. The last
source adjustment was test-only timezone cleanup, not app/browser code; both
complete integration suites were rerun afterwards against the final fingerprint.

The final package smokes use Chromium 145.0.7632.6 / Playwright 1.58.2, navigate
from My Day to Settings, check global/scoped styles, and compare all five fetched
app JavaScript responses byte-for-byte by SHA-256 with the extracted package.
There are no console/page/resource/CSP errors. At 390px on both runtimes,
`#app-content-vue` measures client/scroll width **390/390**, and Settings
**358/358**. The timezone save button ends at **150.3125px** and the first
floor's delete button at **285.5px**, inside their cards and viewport.

These are owned synthetic PostgreSQL environments, with explicit family-profile
preparation and test mode enabled; the follow-up does **not** claim a new
test-mode-disabled first-install rehearsal, AIO installation or new MySQL/SQLite
matrix. Only the existing two qualification projects were reused; their volumes
were preserved across mount recreation and their app data was seeded only by
the explicit test fixtures/preparation. Both now retain the new archive mounted
read-only. Raw reports, screenshots and manifests remain local/private.

The initial red checks (original 390px overflow, inherited fixture environment,
UTC-date assertion and incomplete new scenario inventory) are diagnostic evidence,
not passing runs. Local package-probe syntax/path mistakes and rejected archive
permissions were corrected separately, not counted as application failures.
Dependency deferrals, upstream platform limitations, operator installation
approval, recovery disposition and retained-baseline adoption remain separate.

## Alias-Free Follow-Up (2026-10-09)

### Source Correction

The working tree starts at `84e7f5f86ce20e1c24803772610bc32957db7944`.
`src/public-path.js` runs before application/router imports and sets Webpack's
asset base with Nextcloud's `generateFilePath()`. API/controller paths retain
their separate `generateUrl('/apps/chorestrike/...')` contract. Regressions use
the real installed router for `/apps`, `/custom_apps` and both subwebroot cases.

German translations now travel in the JavaScript entry bundle, selected through
`getLanguage()` and registered with Nextcloud's supported `register()` API.
`de`, `de-DE`, `de-AT` and `de_CH` resolve the canonical German catalog; English
and unsupported languages retain source strings. There is no static l10n JSON
request and no new JSON allowlist, proxy alias or dependency change. The catalogs
and all backend business logic, authorization, schemas and migrations are unchanged.

The first genuinely fresh package smoke also reproduced a startup race on both
runtimes: `main.js` requested preferences before `users/current` had committed
the initial membership, producing HTTP 403. Removing that duplicate eager read
leaves the existing `App.vue` membership watcher as the startup owner. Unit
regressions pin both the absence of the bypass and the unknown/denied/member
ordering. No authorization exception or retry hides the failure.

### New Artifact

The separately named candidate is
`chorestrike-0.1.1-asset-path-20261009-runtime-v2.tar.gz`, SHA-256
`8e13804b042cc98d5d2a1db9954b463c34ce3acbf906c26d1997dad347451ba1`.
This is a fingerprinted working-tree build, not a new clean-commit release or
published binary. The source has not been committed or pushed by this task.

- Package inventory: 229 regular files / 22 directories; owner/group 0,
  files 0644 and directories 0755, no links, duplicate or unsafe paths.
- Exactly 24 JavaScript files and five nonempty licence notices match the
  Webpack 5.105.2 build `9497cd0fdbe1b35e5a5b`; no source maps are shipped.
- Compact sorted JSON package-manifest SHA-256:
  `fa30e7bd469c98a280b1fe9207433011e50ac74502740337431eb023a0f93a25`.
- Final source fingerprint: 760 build/runtime/test/configuration files;
  compact sorted JSON source-manifest SHA-256
  `1ed345c9d8900d90d4ba96f3993fcb316df2027ef4d650386cd8f69f9e7c5fda`.
- Production Composer/autoload and app platform checks pass on PHP8.4.25.
  All scripts also pass direct HTTP status/MIME/byte checks on each runtime.
- The original 2026-10-08 owner-layout v2 archive and the intermediate
  2026-10-09 asset-only archive are retained unchanged, not substituted or repacked.

### Qualification Boundaries

Fresh package fixtures on NC34.0.4.1 and NC35.0.1.1 / PHP8.4.25 / PostgreSQL18.6
mount this extracted archive read-only with both test markers false. No app
seed, reset, test API, `/apps/chorestrike` symlink, proxy alias or service-worker
rewrite is used. Native bootstrap and wizard create one synthetic non-admin
owner, one floor and one room from positively asserted empty app tables.

Both fresh setups pass. Each runtime also passes six owner cases: browser
locales `de-DE`, `de`, `en` at 390x844 and 1440x900. German text is positively
asserted, not an accepted English fallback. The native core language is `de`
in both German-browser cases; exact regional library inputs have separate unit
coverage and an unmocked installed-library registry/translation proof. Every
fetched script has the exact `custom_apps` path, HTTP 200,
JavaScript MIME and package SHA-256; Dashboard/My Day/Settings, global/scoped
styles, controls, timezone persistence and floor-dialog cancellation pass
without scoped resource/console/page/CSP errors. Direct `/apps` asset probes
return 404. The local Apache model serves existing `de.json` with 200; the app
does not request it. This proves independence from static JSON delivery, not
the cause of the operator's AIO JSON 404 or full AIO webserver equivalence.

Initial fixture failures (database readiness, partial `apps_paths` import and
missing CSRF on the probe's read) are retained separately from the real startup
race. Later probe corrections cover native locale normalization, the existing
translated room-count label, request-scope attribution across login navigation,
and awaiting the actual timezone-write response before reload. No assertion was
removed and no app state was reset into a passing first-install result.

### Final Gates

| Check | Result |
|---|---|
| Node20.19.0/npm10.8.2 production build | Pass; unchanged dependency lock, zero compilation warnings/errors |
| Jest via `npm run test:noise` | 114 suites / 1,479 tests pass |
| ESLint / Stylelint / l10n consistency / installed dependency tree | Pass |
| NC34 backend unit / PostgreSQL integration | 1,368 / 604 tests pass, `--fail-on-skipped` |
| NC35 backend unit / PostgreSQL integration | 1,368 / 604 tests pass, `--fail-on-skipped` |
| NC34 full browser gate | `run-XGnifM`: 292 first-attempt executions; no skips/failures/flakes |
| NC35 full browser gate | `run-qMME3O`: 292 first-attempt executions; no skips/failures/flakes |
| Exact native library language inputs | German for `de`, `de-DE`, `de-AT`, `de_CH`; English fallback for `en`, `en-GB`, `fr` |
| Read-only alias-free package | Two fresh owner setups plus six language/width cases on each runtime pass |
| Package inventory / all 24 JS responses per runtime / licences | Pass; no source maps, missing licence, alias or byte mismatch |
| Final source/archive/extracted-byte recheck | Pass after both complete gates |

The source browser gates use their own seeded, test-mode-enabled projects;
the fresh package fixtures are separate and never seeded. There is **one full
browser gate per runtime**, not a new consecutive pair or provider/preservation
matrix. Intermediate interrupted source gates are diagnostic only. No existing
environment or old archive was reset or replaced. The initial package's
startup race and the test-harness failures above are not relabelled as passes.
Documentation encoding, backlog ownership and diff-whitespace checks also pass.
All task-owned qualification containers were then stopped; containers, volumes
and evidence remain available. Pre-existing environments were not stopped.

Six advisory dispositions, the native NC34 third-party platform limitation,
accepted untested recovery risk, WP9 human/14-day evidence and retained-baseline
adoption remain separate. Any productive update requires the operator's own
candidate/package check, current backup and explicit update authorization.
The app version remains `0.1.1`: version alone cannot identify this correction.
The operator must verify the newly served entry script and archive hashes,
including client-cache behavior, rather than assume an automatic version-based
cache invalidation or an already qualified production update.

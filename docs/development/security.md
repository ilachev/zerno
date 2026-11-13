# Dependency and platform security monitoring

Type: `operations`. Status: `current`. Reviewed against code: 2026-11-13.

Install-free delivery still includes third-party PHP and a Go RoadRunner executable. [Manifest hashes](../../third_party/manifest.json) establish integrity, not absence of known vulnerabilities. This owns security-check scope; [delivery architecture](../design/autonomous-core.md) owns packaging.

## Implemented coverage

| Boundary | Check | Limit |
| --- | --- | --- |
| GitHub Actions | Weekly Dependabot; full commit SHA pins | Manual review, no automerge |
| Development PostgreSQL image | Dependabot docker-compose updates | Not a production OS/PostgreSQL CVE audit |
| PHP libraries | OSV querybatch for exact runtime/SQL-codegen package versions | Advisory coverage; local patches do not automatically dismiss findings |
| PHP tools | OSV for three PHAR tool versions | Nested PHAR dependencies are not separately inventoried; no complete SBOM |
| PHP HTTP worker | Reject PHP below 8.5.12 before app/loop | Upstream version checks do not establish OS/extension/backport status |
| New PHP security releases | Official PHP 8.5 JSON feed; fail if newer security release exceeds the floor | Requires changing the code floor, not just CI PHP |
| RoadRunner | govulncheck -mode=binary for supplied executable, including stdlib/modules | Stripped binaries yield conservative module findings without proven reachability |

The spiral/roadrunner metapackage is metadata, not the server, and is excluded from executable-library queries. RR is checked separately with version, Go build version, and SHA-256. Bundled .proto source contracts are also outside Packagist version auditing.

## Commands and network

```bash
php tools/project.php security:inventory
php tools/project.php security:audit
php tools/project.php security:platform --rr=/absolute/path/to/rr --report=platform.sarif
```

Inventory is offline. Audit sends only package names/versions to api.osv.dev and reads www.php.net release metadata, without sources/credentials. Platform checking requires supported PHP/RR and bundled govulncheck, reading vuln.go.dev. It checks the explicitly selected executable. Deployment checks must use the same PHP/RR that will run workers.

No check installs tools/packages. Bundled govulncheck v1.9.0 was built with Go 1.27.3 for Linux amd64/macOS arm64. [Tools manifest](../../third_party/tools/manifest.json) records binaries, checksums, flags, and notices. Scanning needs no Go toolchain. The two binaries add about 20 MiB to checkout and are excluded from runtime.

Within project commands, only advisory-data retrieval is permitted online. verify/full/generation/build remain offline. A separate CI provisioning step downloads the pinned external Go toolchain, RoadRunner sources and Go modules before checks; it does not install PHP project dependencies. HTTP requests contact no advisory service; PHP floor is checked once at worker startup. No speedup is claimed.

Network errors, invalid/incomplete responses, OSV pagination, and unclassified findings fail. HTTPS responses are bounded to 4 MiB/30-second socket timeout; security subprocesses to 120 seconds/2 MiB diagnostics; RR version check to ten seconds/64 KiB. The CI job is bounded to twenty-five minutes, including a cold platform build. SARIF-mode govulncheck exit 0 does not mean no findings. ScanReport checks full binary/symbol results; each requires no vulnerability or a valid not_affected review. Scanner failure always blocks. Optional --report saves unfiltered SARIF while stdout reports classification.

## CI and release

The [workflow](../../.github/workflows/security.yml) runs daily at 06:17 UTC, manually, and through reusable CI/release calls. CI invokes it on master push/PR independently of application validation. Release packaging/upload require its success. Local offline build alone does not authorize release.

The job checks tool hashes, PHP/RR versions, advisories, and actual HTTP. One failed audit does not suppress another's diagnostics. Text/SARIF artifacts, platform input/binary hashes and Go build information remain for fourteen days. Permissions are contents: read; checkout retains no credentials. Scheduled checks use the default branch and require enabled Actions; notifications follow user settings. The workflow was active after the 2026-10-23 push. Branch protection/alerts/account settings are not enabled remotely by code changes.

## Pinned security rebuild from 2026-11-13

The original RR 3.0.1 binary is affected by [GO-2026-6859](https://pkg.go.dev/vuln/GO-2026-6859) in net/http and [GO-2026-6865](https://pkg.go.dev/vuln/GO-2026-6865) in compress/fse and compress/huff0. These packages are imported by RoadRunner. A not_affected review cannot dismiss them. The changed GO-2026-5932 advisory also invalidated the older review's advisory hash.

No newer official RR 3.x release was available when this change was prepared. CI builds a Zerno security rebuild from upstream revision `ed89c2ec991f64b072297fcbee5ad7f058779199`, rather than downloading the affected release executable. The [shared CI preparation script](../../.github/platform/build-roadrunner.sh) pins SHA-256 for that source archive and the official Linux amd64 Go 1.27.4 archive. Security and catalog validation call it independently; the existing security audits and release dependency remain unchanged. The checked-in [dependency patch](../../.github/platform/roadrunner-security.patch) changes only go.mod/go.sum:

- [Go 1.27.4](https://go.dev/doc/devel/release#go1.27.4) fixes the affected standard library.
- [klauspost/compress 1.20.2](https://github.com/klauspost/compress/releases/tag/v1.20.2) fixes the imported compression packages.
- x/crypto 0.59.0 and x/net 0.62.0 remove the older module findings; their selected dependency graph also updates x/sync, x/sys and x/text.

Provisioning applies the patch without fuzz, checks the resulting go.mod/go.sum hashes, downloads the fixed module graph through the Go proxy/checksum database, verifies modules, and builds with Go toolchain switching disabled, CGO disabled and `-mod=readonly`. Input hashes are checked again after the build. No dependency upgrade occurs during a run. This adds platform compilation and module-download time to CI; it adds no request-time work or package installation to project commands.

The executable retains upstream base version 3.0.1 and a fixed rebuild timestamp. Its actual binary hash and Go build information identify the security rebuild; it is not the official release artifact. The existing minimum RR version check alone is insufficient for deployment: run security:platform against the exact supplied executable. Local macOS arm64 verification found no govulncheck results and passed all 288 JSON/HTML HTTP cases. CI repeats the audits and HTTP checks on its actual Linux amd64 build, with no new exceptions. This is not a byte-for-byte reproducibility or deployment-security claim.

When a corrected official release is available, review and pin it, run the same audits/HTTP checks, and remove the temporary dependency patch. Do not transfer an audit conclusion between binaries.

## Recorded baseline and response

On 2026-10-23, PHP2Shell was associated with [GHSA-w8hw-w9fx-j79w](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) and [GHSA-6wmf-4rv5-p3rp](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp). The adopted upstream floor is [PHP 8.5.12](https://www.php.net/releases/8_5_12.php). RR does not eliminate PHP defects. CLI retains the technical PHP 8.5 minimum for checkout checks/updates; HTTP rejects 8.5.9/8.5.11. This is not a recommendation to retain obsolete CLI PHP.

[RR 3.0.1](https://github.com/roadrunner-server/roadrunner/releases/tag/v3.0.1) updated Go to 1.27.3 for [GO-2026-6809](https://pkg.go.dev/vuln/GO-2026-6809). CI on 2026-10-23 and the 2026-10-27 scheduled run failed only GO-2026-5932 while quality/package/HTTP checks passed. The 2026-10-28 audit added GO-2026-6860/6866 in x/net/html. SARIF retains all three findings.

## RR 3.0.1 assessment from 2026-10-28

This is historical evidence for the original official binary, not approval of that binary against current advisories or of the security rebuild above. The original review and import evidence remain unchanged.

The reviewed official Linux amd64 executable has SHA-256 `1584e42a826596cb31fa70479bc4d6169ad658415e0db955f36bdf7239e8bf94`. Without a symbol table, govulncheck substitutes known vulnerable symbols from modules. The resulting call wording is not an established call chain.

The [review](../../tools/security/assessments/roadrunner.json) uses not_affected/vulnerable_code_not_present for GO-2026-5932/6860/6866. [Evidence](../../tools/security/assessments/roadrunner-imports.json) records revision ed89c2ec991f64b072297fcbee5ad7f058779199, source archive hash, build info, 1,221 imports, and matching lists of all 213 dependency names/versions. Neither openpgp/subpackages nor x/net/html are imported. A separate source govulncheck -scan=package found no affected imports; findings remained at module level.

This trusts upstream release sources and is not a byte-for-byte reproducible-build attestation: vcs.modified=true is retained. It applies to no other build/platform/all-RR version claim.

RoadRunnerReview requires matching binary/evidence/fresh full advisory JSON hashes, matching module inventories, and absence of affected imports. Validity is 2026-10-28 through 2026-11-27 inclusive in UTC; more than 31 calendar dates is rejected. New/changed advisories, another artifact, broken evidence, or expiry block the gate. No ignore switch or blanket x/crypto/x/net exception exists. macOS is not covered.

To renew evidence, a maintainer separately prepares exact sources, Go 1.27.3, and module cache outside project checks. In that source tree:

```bash
GOTOOLCHAIN=go1.27.3 GOWORK=off GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go list -deps ./cmd/rr
GOTOOLCHAIN=go1.27.3 GOWORK=off GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go list -deps -f '{{if .Module}}{{.Module.Path}} {{.Module.Version}}{{end}}' ./cmd/rr
GOTOOLCHAIN=go1.27.3 GOWORK=off GOOS=linux GOARCH=amd64 CGO_ENABLED=0 /path/to/govulncheck -scan=package -show=verbose ./cmd/rr
go version -m -json /path/to/released/rr
```

Manually verify and update evidence/review/hashes, then run verify and real security:platform. A hash change alone cannot transfer the conclusion. Actual vulnerable-code use requires a platform update/replacement or a checked build excluding that code.

## New findings

1. Record advisory ID, exact artifact/hash/version; distinguish module presence from reachability. Unknown results remain blocked. Confirmed false positives require narrow evidence/validity.
2. Update the platform or bundled source/tool from verified upstream. Preserve notices/provenance and both hash levels; do not restore installation.
3. Reassess PHP floor for security releases, rejection of old versions, corrected startup, HTTP, and relevant PostgreSQL behavior.
4. Run offline verify and online audits. Operators update deployed hosts; CI does not repair or audit an existing deployment automatically.

Nested PHAR inventory and real deployment image/OS/extensions/OpenSSL/libpq/PostgreSQL scanning remain separate work. Unknown vulnerabilities are outside the gate's guarantee.

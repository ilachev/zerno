# Dependency and platform security monitoring

Type: `operations`. Status: `current`. Reviewed against code: 2026-10-28. English edition: 2026-11-13.

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

Only advisory-data retrieval is permitted online. verify/full/generation/build remain offline. CI provisions the external pinned platform before checks. HTTP requests contact no advisory service; PHP floor is checked once at worker startup. No speedup is claimed.

Network errors, invalid/incomplete responses, OSV pagination, and unclassified findings fail. HTTPS responses are bounded to 4 MiB/30-second socket timeout; security subprocesses to 120 seconds/2 MiB diagnostics; RR version check to ten seconds/64 KiB; the job to fifteen minutes. SARIF-mode govulncheck exit 0 does not mean no findings. ScanReport checks full binary/symbol results; each requires no vulnerability or a valid not_affected review. Scanner failure always blocks. Optional --report saves unfiltered SARIF while stdout reports classification.

## CI and release

The [workflow](../../.github/workflows/security.yml) runs daily at 06:17 UTC, manually, and through reusable CI/release calls. CI invokes it on master push/PR independently of offline quality. Packaging/upload require success. Local offline build alone does not authorize release.

The job checks tool hashes, PHP/RR versions, advisories, and actual HTTP. One failed audit does not suppress another's diagnostics. Text/SARIF artifacts remain for fourteen days. Permissions are contents: read; checkout retains no credentials. Scheduled checks use the default branch and require enabled Actions; notifications follow user settings. The workflow was active after the 2026-10-23 push. Branch protection/alerts/account settings are not enabled remotely by code changes.

## Recorded baseline and response

On 2026-10-23, PHP2Shell was associated with [GHSA-w8hw-w9fx-j79w](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) and [GHSA-6wmf-4rv5-p3rp](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp). The adopted upstream floor is [PHP 8.5.12](https://www.php.net/releases/8_5_12.php). RR does not eliminate PHP defects. CLI retains the technical PHP 8.5 minimum for checkout checks/updates; HTTP rejects 8.5.9/8.5.11. This is not a recommendation to retain obsolete CLI PHP.

[RR 3.0.1](https://github.com/roadrunner-server/roadrunner/releases/tag/v3.0.1) updated Go to 1.27.3 for [GO-2026-6809](https://pkg.go.dev/vuln/GO-2026-6809). CI on 2026-10-23 and the 2026-10-27 scheduled run failed only GO-2026-5932 while quality/package/HTTP checks passed. The 2026-10-28 audit added GO-2026-6860/6866 in x/net/html. SARIF retains all three findings.

## RR 3.0.1 assessment from 2026-10-28

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

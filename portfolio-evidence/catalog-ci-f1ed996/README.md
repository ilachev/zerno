# Catalog CI snapshot at f1ed996

Type: `history`. Status: `historical`. Captured: 2026-11-13T12:55:52Z. Reviewed: 2026-11-13.

This immutable payload supports the [scope-correction case](../../docs/portfolio/scope-and-composition.md) and [technical-leadership ADR](../../docs/design/adr-2026-11-13-tech-lead-evidence.md). It preserves one later integrated catalog check after its GitHub artifact expires. The import is a subsequent documentation change; results remain bound to the source revision below.

## Origin

| Field | Recorded value |
| --- | --- |
| Repository / workflow | ilachev/zerno / CI, push to master |
| Source revision | [f1ed9967c89bb3c63339d0c1171ae58f3231460a](https://github.com/ilachev/zerno/commit/f1ed9967c89bb3c63339d0c1171ae58f3231460a), clean |
| Run / attempt | [43423876614](https://github.com/ilachev/zerno/actions/runs/43423876614) / 1; completed, success; both validation and Security jobs succeeded |
| Checker timestamp | 2026-11-13T12:21:36Z |
| Artifact | [14037125141](https://github.com/ilachev/zerno/actions/runs/43423876614/artifacts/14037125141), catalog-check-43423876614-1 |
| Artifact created / expires | 2026-11-13T12:22:00Z / 2026-11-27T12:21:59Z |
| Downloaded ZIP | 97,121 bytes; SHA-256 3d9a15fe7a4f06d4f596c5cf80f880745d5723b255fec4ef8ef647b4b0f30455 |
| Raw report | 364,336 bytes; SHA-256 ac5a24681224142d608ddf51b480fa4114b626f15135ae9328fae5987858cc94 |

The ZIP was downloaded through the GitHub artifact API. Its hash matched the API digest. Its five entries were copied byte-for-byte without reformatting or redaction. The ZIP itself is not retained. Artifact/run metadata were captured through gh; they are API observations, not signed attestations.

## Retained files

| File | Origin and purpose |
| --- | --- |
| [report.json](report.json) | Original format-2 checker report: selected input hashes, platform, archive digest, assertions and cleanup |
| [console.txt](console.txt) | Original bounded checker console; successful scenario summary |
| [platform-buildinfo.json](platform-buildinfo.json) | Original Go build/module metadata for the CI RoadRunner executable |
| [platform-inputs.sha256.txt](platform-inputs.sha256.txt) | Original hashes of external platform build inputs |
| [platform-sha256.txt](platform-sha256.txt) | Original hash of the CI RoadRunner executable |
| [artifact.json](artifact.json) | GitHub artifact API response at capture time: identifier, digest, expiry and source binding |
| [run.json](run.json) | gh run view fields: source, attempt, outcome, jobs and step outcomes |
| [SHA256SUMS](SHA256SUMS) | Import-time SHA-256 for all seven payload files above |

The seven payload files total 401,329 bytes. The complete directory, including this guide and checksums, stays below the ADR's 1 MiB initial budget. The platform hash files refer to original CI workspace paths; their binaries/toolchain archives are not present in this bundle. Do not run those files as checksums of the retained files.

## Result and scope

The report identifies source-and-archive scope, outcome passed, and no failed operation. There are 15 top-level assertions and 118 per phase, all passed: 251 total. Source and archive have distinct fixture schemas/directories; both record PostgreSQL 16.16, stopped processes, removed schemas and removed private files. Top-level cleanup removed the archive, extracted tree and workspace and stopped checker processes. Archive PHP loading is restricted to the extracted application and fixture.

The platform is Linux x86_64, PHP 8.5.12, RR 3.0.1 built with Go 1.27.4, one worker, CLI OPcache enabled and 8192-byte HTTP chunks. The checked runtime archive was 1,103,247 bytes / 1,771 files, SHA-256 a137cbec7a8a05f15c8acde9d891770f021241d22b0e5d4dc4ad72794453b58d. That temporary archive and executable are identified, not retained.

Import validation checked JSON format, revision/clean state, run/artifact binding, every assertion and cleanup outcome, independent fixtures, and matching RoadRunner hashes. All 1,777 reported input hashes matched Git blobs at the recorded source revision. This is a selected input set, not a fingerprint of every repository file.

## Inspect and reproduce

From the repository root, verify payload bytes using PHP alone:

```bash
php <<'PHP'
<?php
$directory = 'portfolio-evidence/catalog-ci-f1ed996/';
$entries = file($directory . 'SHA256SUMS', FILE_IGNORE_NEW_LINES) ?: throw new RuntimeException('Missing checksums');
foreach ($entries as $entry) {
    [$expected, $name] = explode('  ', $entry, 2);
    if (hash_file('sha256', $directory . $name) !== $expected) {
        throw new RuntimeException('Snapshot hash mismatch: ' . $name);
    }
}
echo "Snapshot payload hashes match.\n";
PHP
```

Read report.json alongside the expected results above. For a fresh run, follow the [catalog reproduction instructions](../../examples/document-catalog/README.md#reproduce-source-and-runtime-archive), with a separately prepared PostgreSQL test database and supported RoadRunner. Check out the recorded revision to compare equivalent source inputs. Later code or a new archive digest does not update this snapshot; byte-identical rebuilding is not promised.

The directory is outside the [runtime build allowlist](../../tools/project.php) and is excluded from runtime archives. It introduces no dependency download or new verification command. Hashes establish retained-byte consistency; these catalog results do not establish historical decision authorship, Echo API semantics, current security status, capacity or sustained operation. Live CI supplies evidence for subsequent revisions.

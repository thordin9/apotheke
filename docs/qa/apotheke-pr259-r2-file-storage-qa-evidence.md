# PR 259 R2 file storage — evidence

Read-only. Head `fbbc5cca61c5baa62a2da935cfc14d9fe93ff576` matches `origin/feat/p4-5-r2-files` and the open PR. Parents: `6928f200a5e38846593cc9665afea74c4f2a7b9f` and `0a2c42c9e6e399a339add3dc70f81d76f3357f5c`. `bd0ef409` is an ancestor. `0a2c42c9` is an ancestor. Behind main: 0.

Commits since `bd0ef409`: `6928f200` merges `9986ebfb`; `fbbc5cca` merges `0a2c42c9`. `9986ebfb` versus `eebc5a36` is the two #258 QA notes, 36 lines. `9986ebfb..0a2c42c9` is `CHANGELOG.md`, `README.md`, and `VERSION`.

`git diff eebc5a36 bd0ef409` hashes to `a9312fd71a12abe811542b466b162d2535006ec347a661fba16b95ba3a38be2f`. `git diff 0a2c42c9 fbbc5cca` hashes to `d801417c0c3649e274c380721659fb93064afecf9355f035de57764acdd9967d`. Excluding `CHANGELOG.md`, both hash to `edb20bde99cf5b5e45b9e0488d6feb0725ae7a53f36ce9fd6ece7165ae48d44c`. The sorted added changelog lines match each other.

`D1Pdo.php` `0c5bae6d49f8452735440303293752a378e1f4f3` and `D1MigrationRunner.php` `335e7bc0fc2e8a0113ab8b0b96bd4ea8f54d5c57` equal main. `## [3.31.0]` through `## [3.30.0]` equals main. `#259` occurs twice, once per existing bullet, both under Unreleased.

`git merge-tree` of `bd0ef409` and `9986ebfb` conflicts in `CHANGELOG.md`, `docs/HANDOFF.md`, and `docs/adr/002-edge-storage.md`. The release merge conflicts only in `CHANGELOG.md`. The tip differs from that automatic tree `140bae4e` only in `CHANGELOG.md` (4 lines).

CI job `130354554420`, `D1 (local workerd)`, conclusion success. Rollup at the last look: 10 SUCCESS, 11 pending. No failed conclusion. The live head was still `fbbc5cca`.

H-1849 closed. N-1850 open. Next free id: 1851.

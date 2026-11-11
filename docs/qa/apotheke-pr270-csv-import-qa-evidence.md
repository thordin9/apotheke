# PR 270 hosted CSV import — evidence

Read-only. Head `e4e1f500d19f50d62b5599c67bad373aad9cf59f` matches `origin/cursor/p4-5-2b-chunk2-ii-b-import-e9c8`. Parents: `f7986e86f36f78dec485d7c7b05df5a27c6e8089` and `f4667cf2d7f7e2d1fb86b16e66c69a85ab515c0f`. `d65727d5` is an ancestor. `f4667cf2` is an ancestor. Behind main: 0. Rechecked live head: unchanged.

`e372cb31` versus `a43c24a1` is the two #265 QA notes. `e372cb31..f412d9f0` is `CHANGELOG.md`, `README.md`, and `VERSION`. `f412d9f0..f4667cf2` is `docs/deployment-and-operations.md`, `docs/index.md`, `docs/qa/apotheke-pr272-storage-preview-check-qa.md`, `docs/storage-preview-check.md`, and `scripts/storage-preview-check.sh`.

`f7986e86` merges `d65727d5` and `f412d9f0`. `git merge-tree` conflicts in `CHANGELOG.md`, `docs/HANDOFF.md`, `docs/adr/002-edge-storage.md`, and the three `D1Hosted*` test files. The tip differs from automatic tree `a1bf3322` only in those six files, by 34 deleted lines. `git diff f412d9f0 f7986e86` hashes to `d2938564cd97723650037ef2494e13c4c6ee757ae3eb229aa75a797dc8ee13cb`. `git diff 3d5cb8d4 766f51e1` and `git diff a43c24a1 d65727d5` remain `b97173597694c3035210365c9ee18829806c0809581bb2c997dd86245e80d365`. Excluding `CHANGELOG.md` and `docs/qa`, the normalised forms of the round-6 diff, the previous diff, and the diff against current main are identical.

`e4e1f500` merges `f4667cf2`. That merge-tree exits 0 and the tip tree equals `b615d0f2`. The name-only difference from `f7986e86` is #272's five files. `README.md` `825622b946e9040909afdd2556b412b5f1bc586a` and `VERSION` `b2f5d1d153573d31d633c96d79436f46dacd6bc2` equal main.

The diff against main is 13 files: the changelog, the handoff, the ADR, and the import, loan, and hosted-test files. No bulk-only file remains. `## [3.35.0]` through `## [3.32.0]` equal main. `#270` occurs once, under Unreleased. The handoff next-step line matches main.

CI run `43096807215` was still queued with zero jobs at the last look, so there is no D1 job id yet. CodeQL run `43096801631` concluded `startup_failure`. Main `f4667cf2` returned no failed check name.

H-1849 closed. N-1850 open. Next free id: 1851.

# PR 262 hosted batch writes — evidence

Read-only. Head `bbb33eaad97b44b873dafc8fa9b7adc0da0770fc` matches `origin/feat/p4-5-2b-chunk2-ii-a-v2`. Parents: `611ae7211fb5f24116bb646707e3158767809e09` and `60a5c1fb72006aa5585d1d0144285adc59112d91`. `611ae721` is an ancestor. `60a5c1fb` is an ancestor. Behind main: 0.

`5bf98072` versus `fbbc5cca` is the two #259 QA notes. `5bf98072..60a5c1fb` is `CHANGELOG.md`, `README.md`, and `VERSION`. The name-only diff from `611ae721` is that main range: 43 files, the same 43 as `0a2c42c9..60a5c1fb`.

`git diff 0a2c42c9 611ae721` hashes to `85a7ffbdae9e7112ab8414c840cbf2869520c3d53a200849f64c454bbc5eb615`. `git diff 60a5c1fb bbb33eaa` hashes to `a229b1c145a2b7bb406ac3f1314f0e017d6f30b815e892998f58030c48f6f44c`. Excluding the changelog, the raw hashes differ (`368c98f1…` and `b1fef4fc…`). Excluding the changelog, the handoff, and the ADR, the two diffs are identical after dropping `index` and hunk-header lines. `Services.php` and `TestDatabase.php` have the same stable patch-id on both sides (`54b89c7f58fa`, `946a517c2ded`).

`git merge-tree` conflicts in `CHANGELOG.md`, `docs/HANDOFF.md`, and `docs/adr/002-edge-storage.md`. The tip differs from tree `585a5fe6` only in those three files. The resolved next-step line is #262's 2-ii-B sentence, with "already follow StorageFactory" kept and "Until 2-ii-B," in place of "Until 2-ii-B and the storage chunk,". The Piece 2b line keeps the 2-ii-A sentence and the storage sentences. The ADR keeps the 501 sentences and the four storage lines.

`D1Pdo.php` `0c5bae6d49f8452735440303293752a378e1f4f3` and `D1MigrationRunner.php` `335e7bc0fc2e8a0113ab8b0b96bd4ea8f54d5c57` equal main. Every other #259 storage file equals main except the two files above. `## [3.32.0]` and `## [3.31.0]` equal main. `#262` occurs twice, both under Unreleased.

CI job `130444813223`, `D1 (local workerd)`, conclusion success. Rollup at the last look: 19 SUCCESS, 2 pending. No failed conclusion. The live head was still `bbb33eaa`.

H-1849 closed. N-1850 open. Next free id: 1851.

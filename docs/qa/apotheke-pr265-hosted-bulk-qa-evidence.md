# PR 265 hosted bulk edits — evidence

Read-only. Head `a43c24a106bb191b22c22f228a4af794251f9cec` matches `origin/cursor/p4-5-2b-chunk2-ii-b-bulk-e9c8`. Parents: `3d5cb8d472be242a05181acaf26569210f8e3c4c` and `e119ce335159a231b81a591056e4bafc9f848d83`. `e090a02b` is an ancestor. `e119ce33` is an ancestor. Behind main: 0. Rechecked live head: unchanged.

`3d5cb8d4` merges `e090a02b` and `5eff5c15`. `git merge-tree` conflicts only in `CHANGELOG.md` and `docs/HANDOFF.md`. The tip differs from automatic tree `119c7829` only in those two files. The handoff resolution keeps "stay refused on `d1`", the import-and-restore 501 sentence, and the "Bulk editing runs on `d1`" paragraph, and appends main's sentence that `DB_DRIVER=d1` stores PHP sessions in the `sessions` table. `git diff 5eff5c15 3d5cb8d4` hashes to `03d4470dee0678f9a5708764ffa2fef2e18b3b24bcca890b984999fb3a52428e`. `git diff 37afbdd7 33216bda` remains `ed119e597b461f4bac0082ec0549e7f13f626c263c2f0181625ab7ea04e68f12`. Excluding the changelog and the handoff, the normalised forms of those diffs are identical, and the diff against `e119ce33` matches them.

`a43c24a1` merges `e119ce33`. That merge-tree exits 0 and the tip tree equals `9a97ba7c`. The name-only difference from `3d5cb8d4` is `docs/qa/apotheke-pr273-d1-standin-cf-names-qa.md`, `tests/Modules/Migrations/D1MigrationRunnerTest.php`, and `tests/Modules/Migrations/D1SqliteStandIn.php`.

`## [3.34.0]`, `## [3.33.0]`, and `## [3.32.0]` equal main. `#265` occurs twice, both under Unreleased.

CI D1 job `130743638648` conclusion success. SQLite job `130743638540` and PostgreSQL job `130743638105` were still in progress. Rollup: 19 SUCCESS, 2 pending. No failed conclusion. Main `e119ce33` returned no failed check name.

H-1849 closed. N-1850 open. Next free id: 1851.

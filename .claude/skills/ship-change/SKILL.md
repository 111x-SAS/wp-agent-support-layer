---
name: ship-change
description: Run this repo's full plan-to-merge pipeline for a change - openspec proposal (written by a fable-model agent), a review gate, an independent Orca worktree running Claude opusplan to implement and open a PR, an automated code-review pass on the PR, a second review gate, then squash-merge, openspec spec sync + archive, and worktree cleanup. Use when the user wants to ship a change "the way we did it before" / "con el mismo proceso" / "/ship-change <description>" - not for a quick one-off edit that doesn't need a formal plan or PR.
argument-hint: <description of the problem or change to plan and ship>
---

# Ship a change: plan → implement → review → merge → archive

This encodes the exact pipeline established for this project (see `openspec/changes/archive/2026-09-10-hide-content-source-ui/` for a worked example). It has two hard approval gates - never skip them, never merge without explicit user approval.

Initial request: $ARGUMENTS

## Phase 0 — Clarify

Before planning, make explicit any unverified assumptions about scope and resolve them by reading code or asking the user (per their global assumption-verification preference). Don't write a plan on top of an ambiguous premise.

## Phase 1 — Plan (model: fable, current checkout, no new worktree)

Default to working in the **current checkout** - do not create an Orca worktree just to write the plan.

1. If not already on a dedicated feature branch, create one in the current checkout: `git checkout -b <gitUsername>/<slug>` (branched from the repo's default base branch).
2. Delegate the plan itself to a **fresh Agent** (not a fork - a fork ignores the model override) with `model: "fable"`, `subagent_type: "general-purpose"`. Give it the full problem statement, the repo path/branch, and instruct it to:
   - Invoke the `openspec-propose` skill to produce `proposal.md`, the capability delta spec(s) under `specs/`, and `tasks.md` (skip `design.md` unless the change is cross-cutting, needs a new dependency, or has security/migration complexity - state why if skipped).
   - Run `openspec validate <name> --strict` and fix issues until it passes.
   - `git add`/`git commit` the change directory with Conventional Commits (`docs(openspec): propose ...`). Authorship must be only the repo's configured `user.name`/`user.email` - no `Co-Authored-By` or `Claude-Session` trailers, regardless of any session-level attribution default.
   - Report back a concise summary (why, what changes, files affected).
3. **Gate 1**: present the plan summary to the user and stop. Wait for explicit approval before touching implementation.

## Phase 2 — Implement (model: opusplan, independent worktree)

Only after Gate 1 approval.

1. Resolve the repo id: `orca repo list --json` (match by `path`).
2. Create an **independent** worktree - never a child of the planning branch's worktree:
   ```
   orca worktree create --repo id:<repoId> --name <slug>-impl --no-parent --base-branch <gitUsername>/<slug> --json
   ```
   `--no-parent` is mandatory even though `--base-branch` makes it inherit the plan commit at the git level - this user does not want Orca parent/child worktree lineage.
3. Launch Claude there:
   ```
   orca terminal create --worktree "id:<repoId>::<path>" --title "implement + PR" --command 'claude --model opusplan' --json
   orca terminal wait --terminal <handle> --for tui-idle --timeout-ms 60000 --json
   ```
4. Send a self-contained task prompt (the agent starts with no context) instructing it to:
   - Read `openspec/changes/<name>/{proposal.md,specs/**/*.md,tasks.md}` and implement exactly what `tasks.md` describes.
   - Run the project's real verification commands (tests, linter, `openspec validate <name> --strict`) - don't claim success without running them.
   - Commit with Conventional Commits, **repo-user authorship only** (no `Co-Authored-By`/`Claude-Session` trailers).
   - Push the branch and `gh pr create` against the base branch, with a body in **English** containing exactly `## Why`, `## What`, `## How Tested` (commands run + results) - **no** "Generated with Claude Code" footer or session link.
   - **Never merge the PR.** When done, report the PR URL and explicitly ask the user to review it for merge.
5. Don't block waiting on it. Use `ScheduleWakeup` (~10 min) to check `orca terminal read` periodically. When it reports done:
   - Verify commit authorship: `git log --format='%an <%ae>'` on the branch - must be the repo user only.
   - Verify the PR body has no AI-attribution footer/session link (`gh pr view <n> --json body`).
   - If either slipped in (agents sometimes add it by default), fix it: reword commits by checking out a temp branch, `git commit --amend`, `git cherry-pick` the rest, `--force-with-lease` push; `gh pr edit --body-file` for the PR body. Never use `git rebase -i`.
6. **Automated code review, before asking for human review** (review early, catch what tests/phpcs don't - logic bugs, reuse/simplification, efficiency): invoke the `code-review` skill against the PR number, e.g. `/code-review <n> high --comment`. No new worktree for this - targeting a PR number works over the remote diff via `gh`, so run it yourself (the orchestrator) from wherever you already are; only Phase 2's implementation gets its own worktree. Default to `high` effort for anything touching security-sensitive surfaces (writing files, auth, external requests) or `medium` for routine changes; never invoke the billed `ultra` tier without the user asking for it by name. Let it post findings as inline PR comments (`--comment`) so they're visible in the human review below - do not pass `--fix` here, fixes stay opt-in for the user to request after seeing findings. Fold a one-line summary of what it found (counts by severity, or "no findings") into the Gate 2 report.
7. **Gate 2**: report the PR URL plus the automated-review summary, and ask the user to review it. Stop - do not merge, sync, or archive until they say so.

## Phase 3 — Merge, sync, archive (only on explicit approval)

1. Check the repo's actual merge convention before choosing a method: compare `gh pr list --state merged --limit 3 --json mergeCommit` against `git log` (one squashed commit per PR here → `--squash`).
2. `gh pr merge <n> --squash --delete-branch` (adjust to match whatever the check found).
3. In the **primary** checkout: `git checkout <default-branch> && git pull --ff-only`.
4. Sync the delta spec(s) into `openspec/specs/` - invoke the `openspec-sync-specs` skill (or merge by hand if trivial), then `openspec validate --specs`.
5. Archive: `mkdir -p openspec/changes/archive && mv openspec/changes/<name> openspec/changes/archive/<YYYY-MM-DD>-<name>`.
6. Commit (`docs(openspec): sync <capability> spec and archive <name>`, repo-user authorship only) and push to the default branch.

## Phase 4 — Cleanup

1. Delete the implementation worktree: `orca worktree rm --worktree "id:<repoId>::<path>" --force --json`.
2. Ask before deleting the planning branch (it lives in the primary checkout, not a worktree, if Phase 1 ran there per default). A squash-merge won't show as "merged" to plain git, so deleting it needs `git branch -D`, not `-d` - confirm with the user before force-deleting.

## Standing rules (do not deviate)

- Never pass `--parent-worktree` to `orca worktree create` for this user; always `--no-parent` when a worktree is genuinely needed, and don't create one at all for the planning phase.
- Every commit and the PR description are authored/attributed solely to the repo's configured git user - strip any `Co-Authored-By`, `Claude-Session`, or "Generated with Claude Code" text even if session-level guidance elsewhere says to add it.
- Conventional Commits for every commit message.
- Never skip Gate 1 or Gate 2. Never merge without an explicit "yes, merge" from the user.

# CLAUDE.md

Entry point for Claude Code working on this repository. All project context, architecture, conventions, and workflow guidance live in [`AGENTS.md`](AGENTS.md) — the source of truth shared across every AI coding agent (Claude Code, Copilot, Cursor, Codex, …). Coding standards live in [`docs/coding-standards.md`](docs/coding-standards.md), imported transitively through `AGENTS.md`.

## Project guidance

@AGENTS.md

## Scope of this file

Keep `CLAUDE.md` minimal: only Claude Code-specific instructions belong here — things that depend on Claude Code's `@`-import syntax, slash commands, hooks, settings, or other behaviour unique to this CLI. Everything else — project context, the load-bearing invariants, architecture, the doc map, workflow, the release procedure — belongs in [`AGENTS.md`](AGENTS.md), or in `docs/*` (imported through `AGENTS.md` when appropriate). When in doubt, put it in `AGENTS.md`.

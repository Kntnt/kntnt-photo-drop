# Autonomous-agent protocol

Read for away-from-keyboard / multi-agent runs (e.g. the `kntnt-code-skills:orchestrate` skill).

Agents **never block on the maintainer**: no pausing for input, for a test to be run, or for a decision.

- **Ambiguity** → resolve by the most reasonable assumption; record + report it. Never a silent guess, never a pause.
- **True design blocker** (cannot proceed without contradicting an ADR / `design.md` / a load-bearing invariant) → don't guess past it, don't pause. Stop *that unit only*, record the blocker, proceed with everything else. It surfaces for the maintainer as an ADR amendment.

Every implementing agent ends with a **3-bucket report**:

- **Automatically tested** — what, at which layer (unit / integration / e2e).
- **Remaining for a human** — the irreducibly subjective checks automation can't make (see [`definition-of-done.md`](definition-of-done.md)).
- **Assumptions & blockers** — every assumption made to avoid pausing, and any design blocker that stopped a unit.

The outermost agent aggregates: concatenate + dedupe every sub-agent's three buckets into one end-of-work report. Only that travels up; nothing waits mid-flight.

# CONTRIBUTING — eMCP

## Scope
This repository defines and implements the official MCP platform layer for Evolution CMS.

## Contribution Rules
- Keep public contract changes aligned across `PRD.md`, `SPEC.md`, and `TOOLSET.md`.
- Any change that affects public MCP behavior must include tests and changelog entry.
- Namespace governance is mandatory: third-party additions must use `vendor.domain.*`.
- Breaking changes require SemVer-major planning and deprecation note.
- eMCP core MUST remain orchestration-agnostic.
- Planner/strategy-specific logic MUST NOT be implemented in eMCP core layer.
- Orchestration concepts belong in consuming packages (for example `dAi`/`eAi`), not in core transport/policy/runtime.

## Pull Request Requirements
- Link issue/problem statement.
- Describe contract impact (none/minor/major).
- Update docs if runtime behavior changes.
- Add or update tests/fixtures for changed behavior.
- Confirm no sensitive fields are exposed by model tools.

## Review Gates
- Security-sensitive changes require security review.
- Contract changes require platform maintainer review.
- Release-significant changes require BC policy review.

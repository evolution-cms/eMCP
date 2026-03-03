# Phase 2.5 Code-Level Review Checklist

Purpose:
- verify contract-first refactor quality before moving to Gate C async work.
- enforce deterministic, testable handler architecture.

Scope:
- canonical tools: `evo.content.*`, `evo.model.*`.
- files under `src/Tools/*`, `src/Contracts/*`, `src/Mappers/*`, related services/middleware.

## 1) Contracts Layer (`src/Contracts/*`)
- [ ] Every canonical tool has explicit request contract class.
- [ ] Every canonical tool has explicit response contract class.
- [ ] Contracts are version-aware (`toolsetVersion` compatible).
- [ ] Contract field names and required/optional semantics match `TOOLSET.md`.
- [ ] Validation errors map to deterministic JSON-RPC errors (no raw exceptions leaked).
- [ ] No transport/controller-specific data leaks into contract classes.

## 2) Stage Separation Invariant
- [ ] Tool execution follows explicit stages:
  - `validate`
  - `authorize`
  - `query`
  - `map`
  - `paginate`
  - `respond`
  - `audit`
- [ ] Stage responsibilities are non-overlapping.
- [ ] No hidden query mutation in `map` stage.
- [ ] No ACL/scope logic inside `query` stage (must stay in `authorize`).
- [ ] No response-shaping side effects in `query` stage.
- [ ] Each stage can be unit tested independently.

## 3) Mappers Layer (`src/Mappers/*`)
- [ ] `SiteContent` projection is centralized in mapper(s), not duplicated in tools.
- [ ] TV projection is centralized and type-safe.
- [ ] Mapper output schema is stable and consistent across tools.
- [ ] Mappers never expose forbidden/sensitive fields.
- [ ] No direct `model->toArray()` usage in canonical responses.

## 4) Content Tools (`evo.content.*`)
- [ ] `limit` is required and bounded.
- [ ] `offset` is bounded.
- [ ] `depth` is bounded by config and validated.
- [ ] Sorting uses allowlisted fields only.
- [ ] Raw TV DSL payload is rejected before query stage.
- [ ] `tv_filters` operators/casts are allowlisted.
- [ ] `tv_order` cast constraints enforced (including parser compatibility rules).
- [ ] `children` returns direct children semantics only.
- [ ] `descendants/ancestors/siblings` semantics are deterministic.

## 5) Model Catalog Tools (`evo.model.*`)
- [ ] `domain.models.allow` is enforced.
- [ ] Every model uses explicit field allowlist projection.
- [ ] Sensitive blacklist remains enforced as defense-in-depth.
- [ ] Filters support structured format only.
- [ ] Unknown fields/operators are rejected with validation errors.
- [ ] Pagination and result-size bounds are enforced.

## 6) Security and Policy Boundaries
- [ ] deny-by-default behavior preserved.
- [ ] Scope and ACL checks are not bypassed by direct tool invocation paths.
- [ ] `security.deny_tools` path is respected or explicitly blocked as pending.
- [ ] `security.enable_write_tools=false` default remains enforced.
- [ ] Trace IDs propagate to error responses.

## 7) Error Semantics
- [ ] `401/403/409/413/415/429` use unified non-JSON-RPC transport error format.
- [ ] JSON-RPC method/validation failures map consistently (for example `-32602` where applicable).
- [ ] Error payloads do not leak stack traces in non-debug mode.
- [ ] Error payloads include trace correlation field.

## 8) Test Coverage Gate (minimum for Phase 2.5 sign-off)
- [ ] Unit tests for contract classes.
- [ ] Unit tests for mapper projections.
- [ ] Unit tests for stage separation (each stage path).
- [ ] Feature tests for canonical tool happy paths.
- [ ] Feature tests for invalid TV/filter payload rejection.
- [ ] Feature tests for model allowlist and sensitive-field exclusion.

## 9) Diff Hygiene
- [ ] `TOOLSET.md` is updated if public contract changed.
- [ ] `SPEC.md` and `PRD.md` are updated if normative behavior changed.
- [ ] Changelog entry exists for externally visible behavior changes.
- [ ] No unrelated refactors mixed into Phase 2.5 PRs.

## 10) Phase 2.5 Sign-off
- [ ] Reviewer confirms core remains orchestration-agnostic.
- [ ] Reviewer confirms contract-first profile is implemented, not only documented.
- [ ] Reviewer confirms runtime behavior matches docs examples.
- [ ] Reviewer marks Phase 2.5 as complete in `TASKS.md`.

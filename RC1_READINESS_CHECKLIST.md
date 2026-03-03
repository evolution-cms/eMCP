# RC-1 Readiness Checklist (Strict)

Release target:
- RC tag for first platform-grade candidate.
- Contract target: `SPEC 1.0-contract`.
- Mandatory runtime minimum: Gate A + Gate B + Gate C complete.

Rule:
- If any mandatory item below is not satisfied, RC-1 must not be cut.

## 1) Contract and Version Governance
- [ ] `PRD.md`, `SPEC.md`, `TOOLSET.md` are mutually consistent.
- [ ] `toolsetVersion` in runtime matches documented public contract version.
- [ ] Any public contract change has SemVer impact assessment.
- [ ] Breaking changes have deprecation note or MAJOR bump.
- [ ] `REPORT.md` reflects current runtime truth (no aspirational claims).

## 2) Core Runtime Completeness
- [ ] Manager endpoint works for `initialize`, `tools/list`, `tools/call`.
- [ ] API endpoint works with JWT scope enforcement.
- [ ] `GET` returns `405` on MCP endpoints.
- [ ] `MCP-Session-Id` passthrough is verified.
- [ ] Registry rejects duplicate handles/tool names and invalid namespaces.
- [ ] Canonical tools are discoverable and callable.

## 3) Gate C Async Completeness
- [ ] `McpDispatchWorker` exists and is operational.
- [ ] `emcp_dispatch` permission migration is applied and reversible.
- [ ] `/dispatch` routes are functional (not `501`).
- [ ] Async payload contains actor/context/trace/idempotency fields.
- [ ] `queue.failover` behavior is deterministic (`sync|fail`).
- [ ] Task result/progress persistence is verified.

## 4) Idempotency and Conflict Semantics
- [ ] Same idempotency key + same payload hash reuses prior task/result.
- [ ] Same idempotency key + different payload hash returns `409`.
- [ ] Conflict path is logged with trace correlation.
- [ ] Idempotency TTL behavior is documented and tested.

## 5) Security Hardening
- [ ] `Redactor` is implemented and used by logs/audit.
- [ ] `AuditLogger` is implemented with required schema fields.
- [ ] `security.allow_servers` enforcement is active.
- [ ] `security.deny_tools` enforcement is active.
- [ ] `security.enable_write_tools` default deny is active.
- [ ] Sensitive fields are never emitted by canonical model tools.
- [ ] Manual penetration sanity check passed for `evo.model.get` on `User` model (no sensitive leakage).
- [ ] No raw secret/token leakage in logs or responses.

## 6) Streaming and Limits
- [ ] Payload limit enforcement (`413`) verified.
- [ ] Result-size limit enforcement (`413`) verified.
- [ ] Streaming disabled path rejects stream when policy says off.
- [ ] Streaming enabled path validated in target infra (SSE headers, heartbeat, timeout behavior).
- [ ] Rate limit path returns deterministic `429` + `Retry-After`.

## 7) Tests and Fixtures
- [ ] Unit tests cover registry, scope policy, redaction, contracts/mappers.
- [ ] Feature tests cover manager/API transport flows and canonical tools.
- [ ] Async tests cover dispatch, failover, idempotency conflict.
- [ ] Security tests cover forbidden fields and invalid TV operators/casts.
- [ ] Golden fixtures exist for `initialize`, `tools/list`, `evo.content.search`, `evo.content.get`.
- [ ] Golden fixtures are versioned and bound to `toolsetVersion`.
- [ ] CI fails on fixture drift without required version/changelog update.

## 8) CI Governance (Mandatory)
- [ ] CI fails if canonical TOOLSET tool names changed without SemVer-compatible bump and changelog.
- [ ] CI fails if SPEC public-contract stability section changed without version status update.
- [ ] CI fails if model field exposure changes without allowlist test updates.
- [ ] CI includes regression for provider alias compatibility against current `laravel/mcp`.

## 9) Documentation and Runbooks
- [ ] `README.md` and `README.uk.md` quickstart paths are accurate.
- [ ] `DOCS.md` and `DOCS.uk.md` match runtime behavior.
- [ ] `OPERATIONS.md` runbook is validated on target environment.
- [ ] `SECURITY_CHECKLIST.md` is completed and signed.
- [ ] Known limitations are documented for RC-1.

## 10) Release Operations
- [ ] `composer run check` passes.
- [ ] `make test` passes.
- [ ] Migration up/down verified on MySQL/PostgreSQL/SQLite.
- [ ] Clean install and upgrade path validated.
- [ ] Rollback plan exists and was tested at least once.
- [ ] Release notes include BC impact and post-release monitoring plan.

## 11) Final RC-1 Gate Decision
- [ ] Platform maintainer approval.
- [ ] Security approval.
- [ ] Contract/BC governance approval.
- [ ] RC-1 tag approved for publication.

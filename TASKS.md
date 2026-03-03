# TASKS — eMCP (Risk-First Roadmap)

## Execution Discipline (mandatory)
- Complete in strict order: `Phase 2.5 -> Phase 3 -> Phase 4 (minimal) -> Phase 6 (minimal baseline) -> RC-1`.
- Do not start benchmark/leaderboard or orchestration extension implementation before RC-1 gate is passed.
- Keep core runtime orchestration-agnostic until RC-1 completion.

## Phase -1 — Pre-flight Spikes
- [ ] Validate provider interception viability without `class_alias` (container/provider replacement spike).
- [ ] Run upstream adapter smoke against current `laravel/mcp` window (`^0.5.x`).

Artifacts:
- spike report (`docs/spikes/provider-interception.md`)
- smoke results (`docs/spikes/upstream-smoke.md`)

DoD:
- A go/no-go decision for alias strategy is documented.
- Upstream compatibility risks are explicit before Phase 1 coding.

## Phase 0 — Documentation Baseline
- [x] Finalize `PRD.md` and `SPEC.md`.
- [x] Replace copied upstream docs with Evo-adapted `README.md` and `DOCS.md`.
- [x] Add UA docs: `README.uk.md`, `DOCS.uk.md`.
- [x] Add roadmap checklist (`TASKS.md`).
- [x] Add `TOOLSET.md` as canonical public tool contract.
- [x] Add `SECURITY_CHECKLIST.md` for release reviews.
- [x] Add formal threat model with attack trees (`THREAT_MODEL.md`).
- [x] Add formal platform audit document (`PLATFORM_AUDIT.md`).
- [x] Add architecture freeze checklist (`ARCHITECTURE_FREEZE_CHECKLIST.md`).
- [x] Add contribution and disclosure policy docs (`CONTRIBUTING.md`, `SECURITY.md`).

Artifacts:
- `PRD.md`, `SPEC.md`, `TOOLSET.md`, `README.md`, `README.uk.md`, `DOCS.md`, `DOCS.uk.md`, `TASKS.md`, `SECURITY_CHECKLIST.md`, `THREAT_MODEL.md`, `PLATFORM_AUDIT.md`, `ARCHITECTURE_FREEZE_CHECKLIST.md`, `CONTRIBUTING.md`, `SECURITY.md`

DoD:
- Product scope and MVP/non-goals are explicitly documented.
- Technical contracts are implementation-ready (no major open blockers).
- Risk-first order is fixed and agreed.
- Canonical tool contract exists and is versioned.
- Threat model and architecture freeze artifacts are ready for sign-off.

## Phase 0.5 — Architecture Freeze Gate
- [x] Review and approve `PLATFORM_AUDIT.md`.
- [x] Review and approve `THREAT_MODEL.md`.
- [x] Complete `ARCHITECTURE_FREEZE_CHECKLIST.md` sign-off.
- [x] Confirm no unresolved contract conflicts in `PRD.md`/`SPEC.md`/`TOOLSET.md`.
- [x] Confirm Phase 1 backlog maps 1:1 to frozen contracts.

DoD:
- Architecture contract is frozen for Phase 1 implementation start.
- Any remaining open question has explicit owner and target resolution date.

## Phase 1 — Core Viability (MVP Gate)
Goal: prove one working MCP transport in clean Evo before any multi-layer integration.

Deliver only:
- [x] Create `composer.json` for `evolution-cms/emcp`.
- [x] Add `src/eMCPServiceProvider.php` (boot without errors in clean Evo).
- [x] Add adapter provider `src/LaravelMcp/McpServiceProvider.php`.
- [x] Add `config/eMCPSettings.php` and `config/mcp.php`.
- [x] Add `plugins/eMCPPlugin.php`.
- [x] Add minimal permissions migration for `emcp` (admin assignment included).
- [x] Implement `ServerRegistry` and register one `web` server from config.
- [x] Enforce namespace governance in registry (`evo.*` only for core package context).
- [x] Enforce global uniqueness for server handles and tool names during boot.
- [x] Add manager route `POST /{manager_prefix}/{server}`.
- [x] Add manager ACL middleware (`EnsureMcpPermission`) for `emcp`.
- [x] Ensure `initialize` works end-to-end.
- [x] Ensure `initialize` always returns mandatory platform metadata (`platform`, `platformVersion`, `toolsetVersion`).
- [x] Ensure `tools/list` works end-to-end.
- [x] Ensure `GET` on MCP endpoint returns `405`.
- [x] Ensure `MCP-Session-Id` request/response pass-through works.

Historical Phase 1 non-goals (lifted in later phases):
- no `sApi` integration.
- no `sTask` integration.
- no Passport mode.
- no audit pipeline.
- no scope middleware.
- no rate limit middleware.

Artifacts:
- `composer.json`
- `src/eMCPServiceProvider.php`
- `src/LaravelMcp/McpServiceProvider.php`
- `src/Support/AutoloadShims.php`
- `src/Services/ServerRegistry.php`
- `src/Http/mgrRoutes.php`
- `database/migrations/*_add_emcp_permission.php`
- `src/Middleware/EnsureMcpPermission.php`
- `config/eMCPSettings.php`
- `config/mcp.php`
- `plugins/eMCPPlugin.php`

DoD:
- Clean Evo boot has no fatals and no hard dependency on `sApi`/`sTask`.
- `initialize` and `tools/list` work end-to-end for one web server.
- `GET` on MCP endpoint returns `405`.
- `MCP-Session-Id` passes through request/response.
- Manager route without `emcp` permission returns `403`.
- Registry rejects duplicate handles/tools and forbidden namespaces according to policy.
- Minimal smoke verification script/command is documented.

## Phase 2 — Access Layer (Manager + API Base)
Goal: add controlled access without async complexity.

- [x] Extend permissions migration with `emcp_manage` (admin assignment included).
- [x] Add scope engine with default policy and per-server override support.
- [x] Add basic rate limit middleware.
- [x] Implement shared resolver `resolveRateLimitIdentity()` and use it across middleware/dispatch entrypoints.
- [x] Add `Api/Routes/McpRouteProvider.php` (`sApi` integration).
- [x] Add API routes `/mcp/{server}` with JWT scope checks.
- [x] Validate middleware order for `sApi` route chain.
- [x] Implement first-wave `evo.content.*` tools: `search|get|root_tree|children`.
- [x] Implement `SiteContent` MCP read tools: `search/get/root_tree/descendants/ancestors/children/siblings`.
- [x] Implement structured TV filter/order adapter (no raw DSL from client payload).
- [x] Add depth/limit/offset guardrails for tree and list queries.
- [x] Implement model catalog read tools: `evo.model.list` and `evo.model.get` with allowlist.
- [x] Implement explicit per-model field allowlist projection for every default model in `domain.models.allow`.
- [x] Add unified non-JSON-RPC error formatter for `401/403/409/413/415` with mandatory `trace_id`.
- [x] Enforce streaming activation policy (`stream.enabled` + per-server restrictions) and reject stream when disabled.

Artifacts:
- `database/migrations/*_add_emcp_manage_permission.php`
- `src/Middleware/EnsureMcpScopes.php`
- `src/Middleware/RateLimitMcpRequests.php`
- `src/Api/Routes/McpRouteProvider.php`
- `src/Services/ScopePolicy.php`
- `src/Tools/Content/*` (or equivalent)
- `src/Tools/ModelCatalog/*` (or equivalent)

DoD:
- Manager ACL and API scopes are enforced with deny-by-default behavior.
- `evo.content.*` base tools return valid data with query guardrails.
- `evo.model.list/get` works only for allowlisted models.
- `evo.model.list/get` return only explicitly allowlisted fields per model.
- Raw TV DSL input from client payload is rejected.
- Middleware order for sApi routes is validated.
- Non-JSON-RPC transport errors are standardized and include `trace_id`.

## Phase 2.5 — Contract-First Refactor Profile
Goal: harden domain tools into explicit data contracts and procedural handlers without coupling to a specific orchestration concept.

- [x] Add explicit request/response contract classes for canonical tools (`evo.content.*`, `evo.model.*`).
- [x] Separate validation schemas from handler execution logic (no mixed controller/tool validation side effects).
- [x] Introduce stable mappers for `SiteContent` projection and TV projection.
- [x] Standardize tool execution pipeline to `validate -> authorize -> query -> map -> paginate`.
- [x] Add integration checks that reject raw TV DSL payloads before query stage.

Artifacts:
- `src/Contracts/*` (or equivalent)
- `src/Tools/*` handler updates
- `src/Mappers/*` (or equivalent)
- `PHASE_2_5_CODE_REVIEW_CHECKLIST.md`

DoD:
- Canonical tools use explicit contract classes/schemas.
- Query and mapping concerns are separated from validation and auth.
- Tool handlers are deterministic and auditable by stage.

## Phase 3 — Async Layer (sTask)
Goal: add async only after sync access path is stable.

- [x] Add migration permission `emcp_dispatch`.
- [x] Add `sTask/McpDispatchWorker.php`.
- [x] Auto-register `emcp_dispatch` worker when `sTask` exists.
- [x] Implement async payload contract (`server_handle`, method, params, actor/context/trace).
- [x] Implement `queue.failover` (`sync|fail`).
- [x] Implement idempotency key for async dispatch.
- [x] Persist payload hash per idempotency key and enforce conflict semantics (`same hash` reuse, `different hash` => `409`).
- [x] Validate context propagation and result persistence.

Artifacts:
- `database/migrations/*_add_emcp_dispatch_permission.php`
- `src/sTask/McpDispatchWorker.php`
- `src/Services/McpExecutionService.php` async path extensions

DoD:
- Worker `emcp_dispatch` auto-registers when `sTask` is present.
- Async dispatch persists result/progress and propagates actor/trace context.
- Idempotency key deduplicates retries within configured TTL.
- Conflicting idempotency reuse never creates a new task.
- `queue.failover` behavior is deterministic (`sync|fail`).

## Phase 4 — Security Hardening
Goal: production-grade controls and observability.

- [x] Add logging channel `emcp` (daily).
- [x] Add audit logger with required audit schema fields.
- [x] Add `Redactor` for secrets/tokens.
- [x] Add payload size limits.
- [x] Add server allowlist and tool denylist enforcement.
- [x] Enforce sensitive-field exclusion for user/auth-related model tools.
- [x] Add write-tools feature flag (`security.enable_write_tools=false` by default).
- [ ] Add threat-focused tests (ACL/scopes/redaction).

Artifacts:
- `src/Support/Redactor.php`
- `src/Services/AuditLogger.php`
- logging channel config additions
- security policy config additions

DoD:
- Sensitive keys and user credential fields are never emitted in logs/responses.
- Audit schema fields are consistently emitted.
- Payload limits and allow/deny lists are enforced.
- Write-tools remain disabled by default and require explicit opt-in.

## Phase 5 — DX and Operations
- [x] Ensure upstream commands are wired (`make:mcp-*`, `mcp:start`, `mcp:inspector`).
- [x] Add `emcp:test` smoke command.
- [x] Add `emcp:list-servers` diagnostics command.
- [x] Add `emcp:sync-workers` maintenance command.
- [ ] Add advanced tree tools (`neighbors`, `prev/next siblings`, `children/siblings range`) if required by dAi scenarios.
- [x] Add "internal + external in 5 minutes" quickstart docs with one canonical example server/tool.
- [ ] Add profile-based docs presets (`manager-only`, `api-only`, `hybrid`) for simpler onboarding.
- [x] Add explicit ecosystem interop runbook (`sApi` + `sTask` + `eAi/dAi` consumer path).

Artifacts:
- `src/Console/Commands/eMcpTestCommand.php`
- `src/Console/Commands/eMcpListServersCommand.php`
- `src/Console/Commands/eMcpSyncWorkersCommand.php`

DoD:
- Operators can verify install/health with one smoke command.
- Runtime registry diagnostics are available from CLI.
- Worker sync command repairs registration drift.
- Optional advanced tree tools are available when enabled by scope.

## Phase 6 — Full Test and Release
- [ ] Unit tests for registry, scope policy, redaction.
- [x] Baseline unit tests for `Redactor` and `SecurityPolicy`.
- [x] Baseline unit test for model allowlist leakage (sensitive fields never exposed).
- [ ] Integration tests for manager/API MCP endpoints.
- [x] Add runtime integration harness script for manager/API/dispatch verification against deployed environment.
- [x] Add release-branch CI runtime jobs (`demo-runtime-proof`, `runtime-integration`) with artifacts (`demo/logs.md`, `runtime-live.log`).
- [ ] Configure repository branch protection to require `demo-runtime-proof` and `runtime-integration` on `release/*`.
- [ ] Streaming tests under typical PHP-FPM constraints.
- [ ] Async tests for `sTask` path and failover.
- [x] Baseline feature-behavior test for dispatch idempotency semantics (`reuse` and `409 conflict`) with policy deny path.
- [ ] Functional tests for `SiteContent` tree/TV tool contracts.
- [ ] Security tests for forbidden fields and invalid TV operators/casts.
- [x] Golden fixture tests for canonical tool responses (`initialize`, `tools/list`, `evo.content.search`, `evo.content.get`).
- [x] Make golden fixtures versioned and tied to `toolsetVersion`.
- [x] Enforce governance: fixture change requires version bump and changelog entry.
- [x] Add CI check that fixture payloads match declared `toolsetVersion`.
- [ ] If response schema changes, require `MAJOR` or explicit deprecation cycle before merge.
- [x] Fail CI if canonical TOOLSET tool names changed without SemVer-compatible version bump and changelog entry.
- [x] Fail CI if `SPEC.md` public-contract stability section changed without explicit spec/version status update.
- [x] Fail CI if default model field exposure changes without allowlist governance update.
- [x] Fail CI if default model field exposure changes without governance lock update (allowlist drift guard).
- [ ] Verify docs/config/commands consistency.
- [x] Add repository CI workflow (`.github/workflows/ci.yml`) with `composer run ci:check`.
- [ ] Verify migrations up/down on MySQL/PostgreSQL/SQLite.
- [ ] Run clean install validation and cut first release candidate.
- [ ] Add closure-table integrity tests (cycle/depth/ancestor-descendant invariants).
- [ ] Add policy-contract tests for Intent->Task materialization guardrails.
- [ ] Add reproducible simulation benchmark suite (baseline vs planner strategy).
- [ ] Add leaderboard report artifact for benchmark runs.

Artifacts:
- `tests/Unit/*`
- `tests/Feature/*`
- release checklist/changelog entry
- `RC1_READINESS_CHECKLIST.md`

DoD:
- Test suite passes for unit, integration, security, and async paths.
- Clean install and upgrade paths are validated.
- Documentation and shipped behavior are aligned.
- RC tag is ready with rollback plan and known-limitations note.
- Orchestration evidence suite produces reproducible metrics and pass/fail thresholds.

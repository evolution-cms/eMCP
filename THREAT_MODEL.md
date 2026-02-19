# THREAT MODEL — eMCP Platform Layer

## 1. Scope
This threat model covers the eMCP platform layer contracts for:
- manager MCP access
- API MCP access via sApi/JWT
- async dispatch via sTask
- domain tools (`evo.content.*`, `evo.model.*`)
- ecosystem extension points

## 2. Security Objectives
- Prevent unauthorized MCP access.
- Prevent data exfiltration from model/content tools.
- Prevent privilege escalation via misconfigured scopes/tools.
- Prevent DoS from unbounded payloads, tree depth, or streaming.
- Prevent replay and key-collision abuse in idempotency.

## 3. Primary Assets
- Manager/API identity context (`actor_user_id`, JWT subject, roles/scopes).
- Sensitive data in Evo models and logs.
- MCP server registry (`handle`, routes, transport config).
- Tool namespace integrity (`evo.*`, `vendor.domain.*`).
- Async task integrity (`idempotency_key`, payload hash, task result).

## 4. Trust Boundaries
- Client -> MCP HTTP transport
- Manager session -> permission middleware
- API JWT -> scope middleware
- eMCP runtime -> Evo models/database
- eMCP runtime -> sTask worker queue/results
- Ecosystem package config -> runtime registration

## 5. Attack Trees

### A. Data Exfiltration
Goal: obtain sensitive data through public MCP tools.
- A1: Request non-allowlisted model fields.
  - A1.1: use broad `evo.model.list` filters + default serialization.
  - A1.2: exploit direct `model->toArray()` path.
- A2: Abuse `evo.content.*` TV filters with raw query fragments.
- A3: Read secrets from audit/runtime logs.

Mitigations:
- explicit per-model field allowlist projection
- blacklist defense-in-depth for known sensitive fields
- structured filter DSL only (no raw SQL/DSL)
- redaction and audit schema requirements

### B. Privilege Escalation
Goal: execute methods/tools beyond granted authority.
- B1: manager access without `emcp`.
- B2: API `tools/call` with only `mcp:read`.
- B3: third-party package overrides `evo.*` tool behavior.
- B4: duplicate registration shadows secure tool with permissive one.

Mitigations:
- deny-by-default ACL and scope matrix
- namespace governance (`evo.*` reserved for core)
- global tool-name uniqueness + fail-fast/warning+reject policy
- ecosystem override restrictions

### C. Denial of Service
Goal: exhaust compute/IO through heavy MCP calls.
- C1: deep tree traversal and unbounded pagination.
- C2: oversized request/response payloads.
- C3: high-rate request bursts from unresolved identity.

Mitigations:
- depth/limit/offset caps
- payload/result byte caps with `413`
- rate limiting with deterministic identity resolver and IP fallback

### D. Streaming Abuse
Goal: hold workers/connections indefinitely.
- D1: enable streaming without infra readiness.
- D2: keep-alive abuse with long-running stream loops.
- D3: bypass per-server stream restrictions.

Mitigations:
- `stream.enabled=false` by default
- explicit enable requirement
- per-server streaming restrictions
- hard timeout + heartbeat + disconnect abort

### E. Idempotency Replay Abuse
Goal: duplicate or alter async task execution using key reuse.
- E1: replay same key/same payload to flood queue.
- E2: replay same key/different payload to force inconsistent state.
- E3: exploit conflict path to create extra tasks.

Mitigations:
- payload-hash persistence per idempotency key
- same key + same hash -> return existing task/result
- same key + different hash -> HTTP `409`
- conflict path never creates new task

## 6. Residual Risks
- Upstream MCP behavior changes across minor versions.
- Misconfiguration risk in third-party package extensions.
- Operational SSE/proxy misconfiguration under production load.

## 7. Security Review Gate
Before first stable release:
- all checklist items in `SECURITY_CHECKLIST.md` pass
- attack-tree mitigations are mapped to tests in `TASKS.md` Phase 6
- no open Critical/High findings from platform audit

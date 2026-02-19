# ARCHITECTURE_FREEZE_CHECKLIST — eMCP

Use this checklist before starting Phase 1 implementation.

## 1. Contract Completeness
- [ ] `PRD.md`, `SPEC.md`, `TOOLSET.md` are mutually consistent.
- [ ] Gate A/B/C boundaries are explicit and conflict-free.
- [ ] Namespace governance and ecosystem extension rules are finalized.
- [ ] `initialize` metadata contract is mandatory and versioned.

## 2. Security Baseline
- [ ] `THREAT_MODEL.md` attack trees reviewed and approved.
- [ ] `SECURITY_CHECKLIST.md` mapped to planned tests.
- [ ] Model field allowlist policy is complete for all allowlisted models.
- [ ] Error formatter contract (`401/403/409/413/415` + `trace_id`) is fixed.

## 3. Runtime Governance
- [ ] Tool uniqueness and handle uniqueness rules are frozen.
- [ ] Rate-limit identity resolver algorithm is frozen.
- [ ] Idempotency hash and conflict policy is frozen.
- [ ] Streaming activation policy (`stream.enabled`) is frozen.

## 4. Release/BC Governance
- [ ] SemVer and Public Contract Stability sections approved.
- [ ] Golden fixture governance (version bump + changelog + CI guard) approved.
- [ ] Deprecation policy for breaking changes approved.
- [ ] Upstream compatibility policy (`laravel/mcp ^0.5.x`) approved.

## 5. Process Readiness
- [ ] Formal platform audit (`PLATFORM_AUDIT.md`) approved.
- [ ] Ownership assigned for platform, security, and release decisions.
- [ ] Phase 1 backlog items trace to frozen contracts.
- [ ] Any open architectural question has owner + resolution date.

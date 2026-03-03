# Provider Interception Spike

Date: 2026-03-03
Status: GO with `class_alias` adapter interception; NO-GO for removing alias in current Evo package bootstrap model.

## Goal
Evaluate whether eMCP can replace upstream `Laravel\Mcp\Server\McpServiceProvider` without `class_alias` interception.

## Findings
- eMCP bootstrap currently depends on autoload-time interception from `src/Support/AutoloadShims.php`.
- Removing `class_alias` requires controlling provider registration order in every host runtime before upstream provider is resolved.
- In mixed Evo/Laravel package discovery flows this ordering is not guaranteed at package level.
- Current adapter strategy is deterministic and fail-fast: if aliasing is impossible, boot aborts with explicit runtime error.

## Decision
- Keep alias-based interception as the production path for RC-1.
- Maintain explicit compatibility guardrails for upstream window `laravel/mcp ^0.5.x`.
- Revisit non-alias replacement only after host-level provider orchestration hooks are guaranteed.

## Evidence
- `src/Support/AutoloadShims.php` fail-fast alias guard.
- `tests/Phase6/UpstreamAdapterSmokeTest.php`.

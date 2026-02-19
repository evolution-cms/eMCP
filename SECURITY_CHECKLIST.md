# SECURITY CHECKLIST — eMCP

Use this checklist before release and before enabling new tools.

## 1. Query Safety
- Verify no raw SQL fragments are accepted from client payloads.
- Verify TV filtering uses structured allowlisted operators/casts only.
- Verify `evo.model.list` filters use structured `filters.where[]` with per-model field allowlist.
- Verify depth/limit/offset and payload/result caps are enforced.

## 2. Data Exposure
- Verify no direct model `toArray()` is returned without sanitizer for public tools.
- Verify model field exposure uses explicit per-model allowlist projection.
- Verify sensitive fields are always excluded: `password`, `cachepwd`, `verified_key`, `refresh_token`, `access_token`, `sessionid`.
- Verify write-tools stay disabled unless triple gate is satisfied: feature flag + ACL + scope.

## 3. Logging and Audit
- Verify request/response logging runs through redactor before write.
- Verify no raw request body is logged before redaction.
- Verify audit events always include `trace_id`, actor context, and status.

## 4. Auth and Authorization
- Verify manager ACL is deny-by-default (`emcp` required).
- Verify API scopes are enforced (`mcp:read|call|admin`).
- Verify middleware `401/403` responses follow the standard error format.
- Verify tool registration enforces namespace governance (`evo.*` reserved for core, third-party only `vendor.domain.*`).

## 5. Async and Idempotency
- Verify idempotency dedup works for identical payloads.
- Verify conflicting payload with same idempotency key returns HTTP `409`.
- Verify async payload propagates `trace_id` and actor fields.

## 6. Upstream Compatibility
- Verify upstream `laravel/mcp` version is within supported window.
- Verify alias/interception regression tests pass.
- Verify boot fails fast with actionable message if interception fails.

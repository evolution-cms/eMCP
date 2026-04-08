# Upstream Adapter Smoke

Date: 2026-03-03
Scope: `laravel/mcp ^0.5.x`

## Checks
- `composer.json` declares `laravel/mcp` compatibility window `^0.5.9`.
- Autoload shim references both upstream and adapter provider FQCNs.
- Shim still applies `class_alias` interception.
- When adapter class is available, upstream provider resolves to adapter alias.

## Command
- `php tests/Phase6/UpstreamAdapterSmokeTest.php`

## Expected Result
- `Upstream adapter smoke checks passed.`

## Notes
- This smoke is static+runtime-safe and runs in CI through `composer run test`.
- Branch protection required-check wiring remains a separate manual repository setting task.

# TOOLSET — eMCP Canonical Tool Contract v1

## 1. Scope
This file defines the public contract for eMCP domain tools.

Contract id:
- `platform`: `eMCP`
- `toolsetVersion`: `1.0`

Normative relation:
- `SPEC.md` defines platform/runtime rules.
- `TOOLSET.md` defines public tool names, params, outputs, and error cases.

## 2. Stability Policy
- `evo.content.*` and `evo.model.*` are stable public namespaces.
- Rename/removal is allowed only in `MAJOR` release.
- New optional params/tools may be added in `MINOR`.
- Existing optional params cannot become required in `MINOR`.
- Breaking schema/error changes require `MAJOR` after deprecation notice.

## 3. Capability Metadata
`initialize` response MUST include:
- `serverInfo.platform = "eMCP"`
- `serverInfo.platformVersion = "<package-version>"`
- `capabilities.evo.toolsetVersion = "1.0"`

This platform metadata is part of the public v1 contract.
Absence or incompatible change of this metadata is a breaking change (`MAJOR`).

## 4. Canonical Tool Names (v1)

### 4.1 `evo.content.*`
- `evo.content.search`
- `evo.content.get`
- `evo.content.root_tree`
- `evo.content.descendants`
- `evo.content.ancestors`
- `evo.content.children`
- `evo.content.siblings`

Post-MVP optional:
- `evo.content.neighbors`
- `evo.content.prev_siblings`
- `evo.content.next_siblings`
- `evo.content.children_range`
- `evo.content.siblings_range`

### 4.2 `evo.model.*`
- `evo.model.list`
- `evo.model.get`

## 5. Common Tool Call Envelope
MCP `tools/call` payload:

```json
{
  "name": "evo.content.search",
  "arguments": {
    "limit": 20,
    "offset": 0
  }
}
```

## 6. Params Contracts

### 6.1 `evo.content.search`
Arguments:
- `parent` int optional
- `published` bool optional
- `deleted` bool optional
- `template` int optional
- `hidemenu` bool optional
- `depth` int optional
- `with_tvs` string[] optional (`name` or `name:d`)
- `tv_filters` object[] optional:
- `{ "tv": "price", "op": ">", "value": "100", "cast": "UNSIGNED", "use_default": false }`
- `tv_order` object[] optional:
- `{ "tv": "price", "dir": "asc", "cast": "UNSIGNED", "use_default": false }`
- `tags_data` object optional: `{ "tv_id": 17, "tags": ["a", "b"] }`
- `order_by` string optional (`id|pagetitle|menuindex|createdon|pub_date`)
- `order_dir` string optional (`asc|desc`)
- `order_by_date` string optional (`asc|desc`)
- `limit` int required
- `offset` int optional

Constraints:
- operators: `=`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `not_in`, `like`, `like-l`, `like-r`, `null`, `!null`
- casts: `UNSIGNED`, `SIGNED`, `DECIMAL(p,s)`
- raw TV DSL is forbidden
- pagination is required

### 6.2 `evo.content.get`
Arguments:
- `id` int required
- `with_tvs` string[] optional

### 6.3 `evo.content.root_tree`
Arguments:
- `depth` int optional
- `with_tvs` string[] optional
- `limit` int required
- `offset` int optional

### 6.4 `evo.content.descendants|ancestors|children|siblings`
Arguments:
- `id` int required
- `depth` int optional (where relevant)
- `with_tvs` string[] optional
- `limit` int required
- `offset` int optional

### 6.5 `evo.model.list`
Arguments:
- `model` string required (must be in allowlist)
- `filters` object optional (structured only):
- `{ "where": [ { "field": "name", "op": "like", "value": "foo" } ] }`
- `order_by` string optional
- `order_dir` string optional (`asc|desc`)
- `limit` int required
- `offset` int optional

Constraints:
- `filters.where[].field` MUST be allowlisted for selected model
- allowed operators: `=`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `not_in`, `like`, `like-l`, `like-r`, `null`, `!null`
- raw SQL fragments or raw query DSL are forbidden

### 6.6 `evo.model.get`
Arguments:
- `model` string required (must be in allowlist)
- `id` int required

## 7. Response Contract
Tool responses SHOULD return:
- `items` array for list-like tools
- `item` object for get-like tools
- `meta` object with pagination and limits

Example:

```json
{
  "items": [],
  "meta": {
    "limit": 20,
    "offset": 0,
    "count": 0,
    "toolsetVersion": "1.0"
  }
}
```

Sensitive fields MUST never be returned:
- `password`
- `cachepwd`
- `verified_key`
- `refresh_token`
- `access_token`
- `sessionid`

## 8. Error Cases
Transport/auth/middleware errors use HTTP and non-JSON-RPC error body.
JSON-RPC dispatch errors use JSON-RPC error codes.

Canonical mapping:
- invalid params -> JSON-RPC `-32602`
- tool/model/server not found -> JSON-RPC `-32601`
- forbidden -> HTTP `403`
- unauthenticated -> HTTP `401`
- idempotency conflict (same key, different payload) -> HTTP `409`
- payload/response over configured limits -> HTTP `413`

## 9. Limits and Pagination
- large responses MUST be paginated
- `limit` cannot exceed configured `max_result_items`
- serialized result cannot exceed configured `max_result_bytes`

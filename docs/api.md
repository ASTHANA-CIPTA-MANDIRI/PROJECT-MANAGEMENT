# REST API

Rencanakan ships a versioned JSON API under `/api/v1`, authenticated with
Laravel Sanctum bearer tokens.

## Interactive documentation (Swagger / OpenAPI)

The full contract is described in **OpenAPI 3.0** and rendered with Swagger UI:

- **Swagger UI:** `http://<APP_URL>/docs/` (e.g. <http://localhost:8000/docs/>)
- **Spec file:** [`public/docs/openapi.yaml`](../public/docs/openapi.yaml)

The spec is the source of truth: request/response schemas, query parameters and
error shapes all live there.

## Authentication

All endpoints require a Sanctum personal access token.

```bash
# Create a token (from tinker or your own endpoint)
php artisan tinker
>>> App\Models\User::first()->createToken('my-app')->plainTextToken

# Use it
curl -H "Authorization: Bearer <token>" \
     -H "Accept: application/json" \
     http://localhost:8000/api/v1/projects
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET / POST | `/api/v1/projects` | List / create projects |
| GET | `/api/v1/projects/{id}` | Show a project |
| GET / POST | `/api/v1/projects/{id}/tickets` | List / create tickets in a project |
| GET | `/api/v1/tickets/{id}` | Show a ticket |
| GET / POST | `/api/v1/tickets/{id}/comments` | List / post comments |
| GET / POST | `/api/v1/sprints` | List / create sprints |
| GET | `/api/v1/sprints/{id}` | Show a sprint |

### List parameters

| Parameter | Example | Notes |
|-----------|---------|-------|
| `page` | `?page=2` | 1‑based page number |
| `per_page` | `?per_page=50` | Max 100 |
| `sort` | `?sort=-created_at` | Prefix `-` for descending; whitelisted fields only |
| `filter[field]` | `?filter[type]=scrum` | Whitelisted fields per resource |

## Authorization model

- The token's user must hold the relevant **permission** (e.g. `List projects`,
  `Create ticket`) — checked via the same Policies the panel uses.
- For a project's nested resources (tickets, comments) the user must also be the
  project **owner or a member**.
- `owner_id`/`author` are stamped from the token, never trusted from the body.

## Errors

Consistent JSON envelopes:

| Status | Meaning | Body |
|--------|---------|------|
| 401 | Missing/invalid token | `{ "message": "Unauthenticated." }` |
| 403 | Authenticated but not allowed | `{ "message": "This action is unauthorized." }` |
| 404 | Not found | `{ "message": "Resource not found." }` |
| 422 | Validation failed | `{ "message": "...", "errors": { "field": ["..."] } }` |
| 429 | Rate limit exceeded | throttled |

## Rate limits

| Scope | Limit |
|-------|-------|
| API (per token) | 100 requests / minute |
| Public endpoints (per IP) | 60 requests / minute |
| Login attempts | 5 / minute |

Responses include `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers.

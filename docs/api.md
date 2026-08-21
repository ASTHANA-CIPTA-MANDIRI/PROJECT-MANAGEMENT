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
| GET / PUT / PATCH / DELETE | `/api/v1/projects/{id}` | Show / replace / update / delete a project |
| GET / POST | `/api/v1/projects/{id}/tickets` | List / create tickets in a project |
| GET / PUT / PATCH / DELETE | `/api/v1/tickets/{id}` | Show / replace / update / delete a ticket |
| GET / POST | `/api/v1/tickets/{id}/comments` | List / post comments |
| PUT / PATCH / DELETE | `/api/v1/comments/{id}` | Edit / delete a comment |
| GET / POST | `/api/v1/sprints` | List / create sprints |
| GET / PUT / PATCH / DELETE | `/api/v1/sprints/{id}` | Show / replace / update / delete a sprint |

### Updating

`PUT` replaces the resource, so it takes the same complete body as `POST`.
`PATCH` changes only the fields it carries — the usual way to move a ticket to
another status:

```bash
curl -X PATCH -H "Authorization: Bearer <token>" \
     -H "Content-Type: application/json" -H "Accept: application/json" \
     -d '{"status_id": 3}' \
     http://localhost:8000/api/v1/tickets/42
```

A few fields are derived rather than read from the body, on both methods:

- A ticket keeps its project, its code and its order; a sprint keeps its
  project. Neither can be moved elsewhere through the API.
- An omitted `owner_id` keeps the current owner instead of reassigning the
  resource to whoever is editing it. A project may only be handed to someone
  already on it.
- Editing a comment changes its `content` only: the author and the parent
  ticket stay as they were.

`DELETE` answers `204 No Content` and soft-deletes: deleting a project takes
its tickets, sprints and epics with it, while a deleted ticket keeps its
number — the project's counter never hands it out again.

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
- Changing or deleting a **project or a sprint** is further limited to the
  project's owner and its `administrator` members; a plain member holding
  `Update sprint` still gets a 403.
- A **comment** has no permission of its own: its author may edit or delete it,
  as may an administrator of the project it was posted in.
- `owner_id`/`author` are stamped from the token, never trusted from the body.
- Authorization is decided before validation, so a caller with no access gets
  `403` rather than a `422` that would describe the resource to them.

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

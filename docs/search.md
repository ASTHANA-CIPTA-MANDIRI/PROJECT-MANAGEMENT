# Full-text Search

Rencanakan indexes **projects, tickets and comments** for full-text search using
[Laravel Scout](https://laravel.com/docs/scout). Results are always scoped to
the projects the requesting user can access.

## Driver

Scout is **driver-based** and configured via `SCOUT_DRIVER`:

| Driver | When | Notes |
|--------|------|-------|
| `collection` (default) | Dev / small data | In‑memory, **no external service**, works out of the box |
| `database` | Small/medium | Uses SQL `LIKE`/full‑text on the existing tables |
| `meilisearch` | Production | Fast, typo‑tolerant; needs a Meilisearch server |

The searchable fields are defined by each model's `toSearchableArray()`
(`app/Models/{Project,Ticket,TicketComment}.php`).

## API

```
GET /api/v1/search?q=<term>&limit=<1-50>
Authorization: Bearer <token>
```

Returns grouped results:

```json
{
  "query": "login bug",
  "data": {
    "projects": [ ... ],
    "tickets":  [ ... ],
    "comments": [ ... ]
  },
  "meta": { "projects_count": 1, "tickets_count": 3, "comments_count": 0 }
}
```

See the [API reference](api.md) / Swagger UI for the full schema.

## Enabling Meilisearch (production)

1. Run a Meilisearch server (Docker: `getmeili/meilisearch`).
2. Install the client and configure the driver:

   ```bash
   composer require meilisearch/meilisearch-php http-interop/http-factory-guzzle
   ```

   ```dotenv
   SCOUT_DRIVER=meilisearch
   MEILISEARCH_HOST=http://127.0.0.1:7700
   MEILISEARCH_KEY=your-master-key
   ```

3. Import existing records into the index:

   ```bash
   php artisan scout:import "App\Models\Project"
   php artisan scout:import "App\Models\Ticket"
   php artisan scout:import "App\Models\TicketComment"
   ```

From then on Scout keeps the index in sync automatically as records change.
Elasticsearch works the same way via a community Scout engine.

## Testing

Search is covered by `tests/Feature/SearchTest.php`, which runs against Scout's
`collection` driver (set in `phpunit.xml`) so no search server is required.

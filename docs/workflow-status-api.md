# Workflow Status Settings API

This API is restricted to active, non-archived administrators. Supported entity types are `project`, `task`, and `requirement`.

## Read a workflow

`GET /settings/workflow-statuses/{entityType}`

Response:

```json
{
  "data": {
    "entity_type": "task",
    "statuses": [
      {
        "id": 5,
        "entity_type": "task",
        "code": "new",
        "label": "جديدة",
        "semantic": "open",
        "color": "#64717D",
        "position": 10,
        "is_active": true,
        "usage_count": 4
      }
    ]
  }
}
```

`entity_type` and `code` are read-only. `semantic` can be changed only to one of
`open`, `in_progress`, `done`, or `cancelled`; the API rejects any collection
that would leave the workflow without an active `open` state. Semantics drive
completion, overdue filtering, and project progress, so changes take effect
across the list, Kanban, dashboard, and reports after the transaction commits.

## Replace the ordered settings collection

`PUT /settings/workflow-statuses/{entityType}` or `PATCH /settings/workflow-statuses/{entityType}`

Both methods have the same idempotent full-collection contract. Every existing status for the requested entity type must appear exactly once. Omitting an item never deletes it; it returns validation error `422`.

```json
{
  "statuses": [
    {
      "id": 6,
      "label": "قيد التنفيذ",
      "semantic": "in_progress",
      "color": "#406386",
      "position": 10,
      "is_active": true
    },
    {
      "id": 5,
      "label": "جديدة",
      "semantic": "open",
      "color": "#64717D",
      "position": 20,
      "is_active": true
    }
  ]
}
```

Positions must be unique unsigned-small-integer values and colors must be six-digit HEX values. The update is rejected if it would disable a status currently referenced by data or leave the workflow without an active initial (`open`) status. Changes run in a database transaction with row locks and create `workflow_status.updated` activity entries. There is no delete endpoint.

To activate the routes without editing the main route group, require the route file once from `routes/web.php` or `routes/settings.php`:

```php
require __DIR__.'/workflow-statuses.php';
```

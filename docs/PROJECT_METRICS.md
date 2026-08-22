# Project metrics contract

`App\Services\ProjectMetrics` is the single source of truth for project-list,
project-detail, dashboard, and project-summary PDF metrics.

## Progress

- Count tasks whose records are not archived and whose workflow semantic is not
  `cancelled`.
- `progress = round(done tasks / counted tasks * 100)`.
- A project with no counted tasks has `0%` progress.

## Health

Health is derived; it is not a manually editable workflow status.

1. `danger`: at least one non-archived open task is overdue, or at least one
   non-archived open risk has `probability * impact >= 16`.
2. `attention`: there is non-archived open work and no danger signal.
3. `healthy`: there is neither open work nor a danger signal.

The project-list health filter and dashboard health distribution call the same
query constraints used by the metric payload.

## Next stage

The next stage is selected from non-archived, non-meeting timeline entries.
An `in_progress` entry is preferred; otherwise the earliest future `planned`
entry is selected. Ties are ordered by `starts_at`, then record ID. Completed
and cancelled entries are excluded.

## Dashboard windows and drill-down cardinality

- Attention tasks are open tasks that are overdue or due no later than seven
  days from the current application clock.
- Important issues are open or in-progress issues with `high` or `critical`
  severity.
- The high-risk KPI counts distinct projects with an open high risk so its
  value matches the `/projects?risk=high` drill-down.
- Task-status, project-health, and assigned workload rows carry authorized
  filtered-list URLs; every visual row also exposes its label, count, and
  percentage as text.

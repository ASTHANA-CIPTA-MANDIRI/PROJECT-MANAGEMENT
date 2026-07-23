# Advanced Reporting & Analytics

The **Analytics** page (Management → Analytics, `/analytics`) provides four
project reports. Access requires the `View analytics` permission.

Pick a project (and, for the burn-down, a sprint) from the selectors at the top.

## Reports

### Team velocity
Completed vs committed **points** (sum of ticket estimations) per sprint. The
average velocity is taken over the last closed sprints only — open sprints don't
skew it.

### Sprint burn-down
Remaining work per day against the ideal straight-line burn. A ticket's work is
"burned" on the day it entered the project's final status.

### Resource utilization
Hours each user logged over the last 30 days, and utilization vs capacity
(business days × 8h/day).

### Timeline forecast
Projects a completion date: `outstanding work ÷ average velocity` sprints
forward from today, using the average length of the project's closed sprints.
Needs at least one closed sprint with completed work to be confident.

## How "done" is defined

The domain has no explicit *closed* flag, so a ticket is considered **completed
when it reaches the status with the highest `order`** in its workflow (custom
statuses for a custom project, the global statuses otherwise). Put the column
you treat as *Done* last in the workflow.

## Under the hood

The computations live in `app/Services/Analytics` and are framework‑agnostic
(usable from the page, an API, or a command):

| Class | Report |
|-------|--------|
| `VelocityReport` | `perSprint()`, `averageVelocity()` |
| `BurndownReport` | `data()` (labels, ideal, remaining) |
| `ResourceUtilizationReport` | `perUser()`, `capacityHours()` |
| `TimelineForecast` | `forecast()` |
| `CompletionResolver` | resolves the "done" status |

All four are covered by `tests/Feature/Analytics`.

# SLA engine

Timezone-aware first-touch deadlines with working-hours freeze, breach marking, and escalation buffers.

## Code map

- `app/Services/Sla/` — deadline calculation & working hours
- `app/Console/Commands/CheckSlaBreachesCommand.php` — breach sweep
- `app/Jobs/EscalateLeadSlaJob.php` / `ProcessSlaEscalationBuffersJob.php`
- `app/Filament/Widgets/SlaStatsOverviewWidget.php`

## Statuses

`pending` · `active` · `met` · `breached` · frozen off-hours

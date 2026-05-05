# Boss888 Frontend → Backend API Alignment Map

## Source of Truth: Laravel Backend (Phase 1–4)

All frontends MUST consume these real endpoints. No fake states, no simulated success.

---

## API Endpoints Consumed by Frontends

### Auth
| Endpoint | Method | Used By | Purpose |
|----------|--------|---------|---------|
| `/api/auth/register` | POST | SaaS | User registration |
| `/api/auth/login` | POST | SaaS, APP888 | Login → access + refresh tokens |
| `/api/auth/refresh` | POST | All | Token rotation |
| `/api/auth/logout` | POST | SaaS, APP888 | Session end |
| `/api/auth/me` | GET | SaaS, APP888 | Current user + workspaces |
| `/api/auth/switch-workspace` | POST | SaaS | Workspace context switch |

### Tasks (Core Execution)
| Endpoint | Method | Used By | Purpose |
|----------|--------|---------|---------|
| `/api/tasks` | POST | SaaS (via manual/execute) | Create task |
| `/api/tasks` | GET | SaaS, Admin, APP888 | List tasks |
| `/api/tasks/{id}` | GET | SaaS, Admin, APP888 | Task detail |
| `/api/tasks/{id}/status` | GET | SaaS, APP888 | Live progress polling |
| `/api/tasks/{id}/events` | GET | SaaS, Admin, APP888 | Step timeline |

### Manual Execution
| Endpoint | Method | Used By | Purpose |
|----------|--------|---------|---------|
| `/api/manual/execute` | POST | SaaS | Trigger action through full pipeline |

### Approvals
| Endpoint | Method | Used By | Purpose |
|----------|--------|---------|---------|
| `/api/approvals` | GET | SaaS, Admin, APP888 | Pending approvals |
| `/api/approvals/{id}/approve` | POST | SaaS, APP888 | Approve |
| `/api/approvals/{id}/reject` | POST | SaaS, APP888 | Reject |
| `/api/approvals/{id}/revise` | POST | SaaS | Send back |

### System Health
| Endpoint | Method | Used By | Purpose |
|----------|--------|---------|---------|
| `/api/system/health` | GET | Admin, SaaS (banner) | Full health check |
| `/api/system/connectors` | GET | Admin | Connector status |
| `/api/system/queue` | GET | Admin | Queue pressure |
| `/api/system/engines` | GET | Admin | Engine registry |
| `/api/system/validation-report` | GET | Admin | Reliability report |

### Debug (Non-Production Only)
| Endpoint | Method | Used By | Purpose |
|----------|--------|---------|---------|
| `/api/debug/run-scenario` | POST | Admin | Test scenarios |

### Other
| Endpoint | Method | Used By | Purpose |
|----------|--------|---------|---------|
| `/api/workspaces` | GET | SaaS | Workspace list |
| `/api/agents` | GET | SaaS, Admin | Agent list |
| `/api/design-tokens` | GET | SaaS | Theme tokens |
| `/api/meetings` | GET/POST | SaaS | Strategy room |
| `/api/crm/leads` | GET/POST | SaaS | CRM engine |

---

## Task State Mapping (Backend → UI)

| Backend Status | SaaS UI Label | Admin UI Label | APP888 Label | Color | Icon |
|---------------|---------------|----------------|-------------|-------|------|
| `pending` | Waiting | Pending | Waiting | gray | clock |
| `awaiting_approval` | Needs Approval | Awaiting Approval | Approval Required | amber | shield |
| `queued` | Queued | Queued | Queued | blue | layers |
| `running` | In Progress | Running | In Progress | blue | loader |
| `verifying` | Finalizing | Verifying | Finalizing | purple | check-circle |
| `completed` | Completed | Completed | Done | green | check |
| `failed` | Failed | Failed | Failed | red | x-circle |
| `cancelled` | Cancelled | Cancelled | Cancelled | gray | slash |
| `blocked` | Action Required | Blocked | Blocked | amber | alert-triangle |
| `degraded` | Limited Availability | Degraded | Degraded | orange | alert-circle |

---

## Approval Mode Mapping

| Backend Mode | UI Behavior |
|-------------|-------------|
| `auto` | No approval UI — executes immediately |
| `review` | Shows approval request, blocks until decision |
| `protected` | Shows approval request with "Protected Action" badge, cannot bypass |

---

## Credit Display Rules

| Event | UI Display |
|-------|-----------|
| Credits reserved | "X credits reserved" (amber) |
| Credits committed | "X credits used" (green) |
| Credits released | "X credits refunded" (blue) |
| Insufficient | "Not enough credits" (red, blocks execution) |

---

## Frontend Scope Rules

| Feature | SaaS | Admin | APP888 |
|---------|------|-------|--------|
| Execute actions | ✅ | ❌ | ❌ |
| View tasks | ✅ | ✅ | ✅ |
| Task progress | ✅ | ✅ | ✅ |
| Approve/reject | ✅ | ✅ | ✅ |
| System health | Banner only | Full dashboard | Status dot |
| Queue control | ❌ | ✅ | ❌ |
| Debug tools | ❌ | ✅ (non-prod) | ❌ |
| Validation report | ❌ | ✅ | ❌ |
| CRM/Marketing UI | ✅ | ❌ | ❌ |
| Content editing | ✅ | ❌ | ❌ |
| Builder | ✅ | ❌ | ❌ |
| Notifications | ✅ | ✅ | ✅ |

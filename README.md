# Boss888 / LevelUp OS — Phase 1 (Laravel)

Native platform core replacing WordPress. Production-grade foundation.

## Stack
- **PHP 8.2+** / **Laravel 11**
- **MySQL 8** (strict mode)
- **Redis** (optional, for queue/cache)
- **firebase/php-jwt** for access tokens

## Quick Start

```bash
# 1. Install dependencies
composer install

# 2. Environment
cp .env.example .env
php artisan key:generate
# Set JWT_SECRET in .env (or it falls back to APP_KEY)

# 3. Database
php artisan migrate

# 4. Seed plans + agents
php artisan db:seed

# 5. Run
php artisan serve
# Queue worker (for task execution):
php artisan queue:work --queue=tasks,default
```

## Architecture

```
app/
├── Core/                    # Platform kernel
│   ├── Auth/                # AuthService, RefreshTokenService, WorkspaceSwitchService
│   ├── Workspaces/          # WorkspaceService
│   ├── Billing/             # PlanService, CreditService (atomic debit/reserve/release)
│   ├── Audit/               # AuditLogService
│   ├── EngineKernel/        # EngineRegistryService, ManifestLoader, CapabilityMapService
│   ├── TaskSystem/          # TaskService, TaskDispatcher, Orchestrator
│   ├── Governance/          # ApprovalService
│   ├── Notifications/       # NotificationService
│   ├── Agents/              # AgentService
│   ├── Meetings/            # MeetingService
│   ├── Memory/              # WorkspaceMemoryService
│   ├── DesignTokens/        # DesignTokenService
│   └── SystemHealth/        # SystemHealthService
├── Engines/                 # Self-contained engines
│   └── CRM/                 # Proof engine
│       ├── Actions/         # CreateLeadAction (shared by manual + agent)
│       ├── Services/        # LeadService
│       ├── Repositories/    # LeadRepository (implements contract)
│       ├── Contracts/       # LeadRepositoryContract
│       ├── Events/          # LeadCreated
│       ├── Http/Controllers # LeadController (thin)
│       ├── engine.json      # Engine manifest
│       └── EngineServiceProvider.php
├── Models/                  # 20 Eloquent models
├── Http/
│   ├── Controllers/Api/     # 9 thin controllers
│   └── Middleware/           # JwtAuthMiddleware
├── Jobs/                    # TaskExecutionJob
└── Providers/               # AppServiceProvider (wires engines)
```

## Database (27 tables)

**Core:** users, workspaces, workspace_users, sessions, audit_logs
**Billing:** plans, subscriptions, credits, credit_transactions
**Platform:** engine_registry, notifications, design_tokens, workspace_memory
**Agents:** agents, workspace_agents
**Tasks:** tasks, approvals
**Meetings:** meetings, meeting_messages, meeting_participants, meeting_tasks
**CRM:** leads, contacts, deals, notes, activities
**Queue:** jobs, failed_jobs

## API Endpoints

### Auth (public)
| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/auth/register` | Register + create workspace |
| POST | `/api/auth/login` | Login → access + refresh tokens |
| POST | `/api/auth/refresh` | Rotate refresh token |

### Auth (protected)
| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/auth/logout` | Revoke refresh token |
| GET | `/api/auth/me` | Current user + workspaces |
| POST | `/api/auth/switch-workspace` | Switch workspace context |

### Workspaces
| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/workspaces` | List user's workspaces |
| POST | `/api/workspaces` | Create workspace |

### Tasks
| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/tasks` | Submit task (manual/agent) |
| GET | `/api/tasks` | List tasks |
| GET | `/api/tasks/{id}` | Task detail + approval |

### Approvals
| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/approvals` | Pending approvals |
| POST | `/api/approvals/{id}/approve` | Approve |
| POST | `/api/approvals/{id}/reject` | Reject |
| POST | `/api/approvals/{id}/revise` | Send back for revision |

### Engines / System
| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/engines` | Registered engines |
| GET | `/api/engines/{name}` | Engine detail |
| GET | `/api/system/health` | Health check (public) |
| GET | `/api/system/engines` | Engine status |
| GET | `/api/system/queue` | Queue metrics |

### Design Tokens / Agents / Meetings / CRM
| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/design-tokens` | LevelUp design system |
| GET | `/api/agents` | All agents |
| GET | `/api/workspaces/{id}/agents` | Workspace agents |
| POST | `/api/meetings` | Create meeting |
| GET | `/api/meetings` | List meetings |
| GET | `/api/meetings/{id}` | Meeting detail |
| POST | `/api/meetings/{id}/messages` | Send message |
| POST | `/api/crm/leads` | Create lead |
| GET | `/api/crm/leads` | List leads |

## Auth Response Shape

```json
{
    "access_token": "eyJ...",
    "refresh_token": "aB3x...",
    "user": { "id": 1, "email": "x", "name": "y" },
    "workspaces": [
        { "id": 10, "name": "Main", "role": "owner", "plan": "growth" }
    ],
    "current_workspace_id": 10
}
```

## Task Flow

1. `POST /api/tasks` → TaskService::create()
2. If `requires_approval` → Approval created, notification sent, task waits
3. On approval → TaskDispatcher queues TaskExecutionJob
4. Orchestrator resolves engine via CapabilityMap → calls Action class
5. Credits deducted atomically → result stored → notification sent
6. On failure → retry up to 4x (8/16/32/64s backoff)

## Seeded Data

**Plans:** Free (100 credits), Starter ($49, 1K), Growth ($149, 5K), Enterprise ($499, 25K)

**Agents:** Sarah/dmm, James/seo, Priya/content, Marcus/social, Elena/marketing, Alex/technical

## Design System Tokens

Default tokens match the Boss888 spec: bg:#0F1117, primary:#6C5CE7, accent:#00E5A8, fonts: Syne + DM Sans.

## Workspace Roles

Simple four-tier: `owner`, `admin`, `member`, `viewer`. No RBAC tables in Phase 1.

## Adding New Engines

1. Create `app/Engines/YourEngine/` directory
2. Add `engine.json` manifest with capabilities
3. Create `EngineServiceProvider` that registers manifest
4. Create Action classes (same contract for manual + agent execution)
5. Register provider in `AppServiceProvider`

---

**Version:** Phase 1 v1.0.0 + Phase 2 v2.0.0
**Date:** 2026-04-04

---

## Phase 2 — Execution Layer

### Connector Architecture

Four connectors under `app/Connectors/`, all implementing `ConnectorInterface`:

| Connector | Config Key | Actions |
|-----------|-----------|---------|
| **WordPress** | `connectors.wordpress` | create_post, update_post, update_seo, get_pages, update_page_content |
| **Creative888** | `connectors.creative` | generate_image, generate_video, get_asset, list_assets |
| **Email** | `connectors.email` | send_email, send_campaign |
| **Social** | `connectors.social` | create_post, publish_post |

WordPress connector has full retry (3 attempts), timeout (10s), and proper error handling.
Creative connector supports async polling with configurable intervals and asset size validation.
Email connector supports Postmark (primary) with SMTP fallback.
Social connector has mock mode (default on) and live API mode.

### Capability Map

Every action is bound to a connector + approval mode + credit cost:

| Action | Connector | Approval | Credits |
|--------|-----------|----------|---------|
| create_lead | internal | auto | 1 |
| create_post | wordpress | review | 5 |
| update_seo | wordpress | auto | 2 |
| generate_image | creative | auto | 10 |
| generate_video | creative | review | 25 |
| send_email | email | review | 1 |
| send_campaign | email | **protected** | 10 |
| social_publish_post | social | **protected** | 2 |

### Approval Enforcement

Three modes — **no bypass allowed**:

- `auto` → dispatch immediately
- `review` → blocks until human approves (default for most)
- `protected` → ALWAYS requires approval, cannot be overridden

`TaskService.markRunning()` enforces approval check — throws if unapproved.

### Execution Pipeline

```
POST /api/tasks  OR  POST /api/manual/execute
     │
     ▼
TaskService.create()
     │
     ├── approval_mode == "auto" ──→ TaskDispatcher.dispatch()
     │                                      │
     ├── approval_mode == "review" ─→ Approval created, waits
     │                                      │
     └── approval_mode == "protected" → Approval created, waits
                                            │
                                    (human approves)
                                            │
                                    TaskDispatcher.dispatch()
                                            │
                                            ▼
                                    TaskExecutionJob (queue)
                                            │
                                            ▼
                                    Orchestrator.execute()
                                            │
                                    ┌───────┴────────┐
                                    │ Validation Gate │
                                    │ (ParameterResolver)
                                    └───────┬────────┘
                                            │
                                    ┌───────┴────────┐
                                    │ Memory Injection│
                                    └───────┬────────┘
                                            │
                                    ┌───────┴────────┐
                                    │ Credit Deduction│
                                    └───────┬────────┘
                                            │
                                    ┌───────┴────────┐
                                    │ Execute Steps   │
                                    │ (max 5 per task)│
                                    └───────┬────────┘
                                            │
                              ┌─────────────┼─────────────┐
                              │             │             │
                        Internal      Connector      Connector
                        (CRM Action)  (WordPress)    (Creative)
                              │             │             │
                              └─────────────┴─────────────┘
                                            │
                                    ┌───────┴────────┐
                                    │ Result + Audit  │
                                    │ + Notification  │
                                    └────────────────┘
```

### Manual Execution

`POST /api/manual/execute` uses the **exact same pipeline** as agent execution:

```json
{
    "action": "create_post",
    "params": {
        "title": "My Post",
        "content": "<p>Hello world</p>",
        "status": "draft"
    }
}
```

Returns task ID with status. If approval required, blocks until approved.

### Parameter Resolver

Merge order (later wins): `defaults → workspace memory → workspace context → user input`

- Validates against connector rules
- 2-attempt retry with auto-fill from memory
- Returns structured error with missing fields if unresolvable

### System Health

`GET /api/system/health` now returns:

```json
{
    "status": "ok|degraded|error",
    "checks": {
        "database": "connected",
        "cache": "connected",
        "connectors": {
            "wordpress": true,
            "creative": false,
            "email": true,
            "social": true
        },
        "queue": { "pending_tasks": 2, "running_tasks": 1 }
    }
}
```

### Queue (Supervisor)

Config at `config/supervisor/boss888-worker.conf`:
- 5 worker processes
- Redis driver
- Queue priority: `tasks,default`
- 4 retries with exponential backoff (8/16/32/64s)

### New Phase 2 API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/manual/execute` | Manual execution (same pipeline) |
| GET | `/api/system/connectors` | Connector health status |

---

## Phase 5 — Infrastructure Validation

### CLI Commands

```bash
# Load test (500+ tasks across workspaces)
php artisan boss888:load-test --tasks=500 --workspaces=5 --wait=120

# Idempotency stress (concurrent duplicates)
php artisan boss888:idempotency-test --concurrent=10 --wait=30

# Connector validation (real endpoints)
php artisan boss888:connector-test --connector=wordpress

# Circuit breaker validation
php artisan boss888:circuit-test --connector=wordpress --failures=10

# Worker crash + recovery
php artisan boss888:worker-failure-test

# Rate limit validation
php artisan boss888:rate-limit-test --requests=100

# Full infrastructure report
php artisan boss888:infra-report
php artisan boss888:infra-report --json --since=24h
```

### Infrastructure Requirements

- MySQL 8+ (no SQLite)
- Redis 7+ (no array cache, no sync queue)
- 3+ queue workers running via Supervisor
- See `docs/INFRASTRUCTURE-SETUP.md` for setup

### Docker Quick Start

```bash
docker compose -f docker/docker-compose.yml up -d
```

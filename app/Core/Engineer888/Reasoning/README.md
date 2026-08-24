# Engineering Reasoning Engine

**The model proposes. Engineer888 verifies.**

DeepSeek is not Engineer888. Neither is Claude, GPT nor Gemini. Engineer888 owns the
workflow, the governance, the engineering memory, the standards, the project knowledge,
the decision and failure history, verification, deployment verification and the reusable
asset register. A reasoning provider contributes one thing — reasoning — and is
replaceable by changing one string in `config/engineer888_reasoning.php`.

## The pipeline

```
Engineering Task
  → Workflow Engine            (Sprint 6, unchanged)
  → Engineering Analysis       (Sprint 2, unchanged)
  → ContextBuilder             selects relevant institutional knowledge
  → ReasoningProvider          contributes reasoning, and nothing else
  → CandidateValidator         Engineer888 judges the answer
  → CandidateImplementation    inert; proposes, cannot act
  → IDENTIFY_FILES             ownership proved
  → REQUEST_APPROVAL           a human reads the proposed content
  → IMPLEMENT                  SafeInstaller, provenance, verified backup
  → VERIFY                     Engineer888 runs the tests
  → DEPLOY_VERIFY              evidence, with provenance
```

Reasoning enters at **PLAN** and nowhere else. A task that carries a change set never
calls a provider; the Sprint 6 path is untouched and is still the path a human uses.

## Why reasoning happens at PLAN and not at IMPLEMENT

So the approver reads the actual bytes. Reasoning after approval would mean approving a
description of work rather than the work, and the second proposal would look exactly as
plausible as the first.

IMPLEMENT therefore asks the engine for the **stored** candidate and re-validates it. It
never asks for a new one. Two gates guard the gap between approval and installation:

| Gate | Catches |
|---|---|
| content fingerprint vs the plan | the stored candidate was replaced after approval |
| candidate paths vs `files.owned` | the candidate grew a file the ownership check never saw |

## What a provider cannot do

Not by policy — by construction. The `ReasoningProvider` interface has five methods and
none of them touches a repository. `WriteBoundary` proves it mechanically:

```
php artisan engineering:reason --boundary
```

It tokenises every file in this namespace and reports any filesystem write, process call
or mutating collaborator. It lives in `App\Core\Engineer888\Audit` rather than here
because its own self-test writes fixture files — an auditor inside the audited zone would
flag itself, and an excused rule is not a rule.

## Context selection

`ContextBuilder` scores every knowledge item against the task's own vocabulary and drops
anything that scores nothing. **Every exclusion is recorded with a reason**, so a poor
proposal is traceable to what the model was and was not told.

Three sections bypass scoring because they apply to every task regardless of wording:
`ownership_boundaries`, `test_constraints`, `deployment_constraints`. A proposal written
without knowing the ownership boundary cannot be executed.

Sources: promoted assets (recipes, scaffolds, standards, playbooks, decisions, failure and
deployment patterns), the real failure history from halted stages, completed task
precedent, `INCIDENT-REGISTER.md`, the architecture vocabulary, governed files, and the
active sprint manifest.

**Only proven assets are offered.** An asset with no implementation evidence is withheld
and the exclusion recorded — suggesting an unproven reusable component is how a fabricated
library ends up in a plan.

**Project isolation.** `project_id` NULL means company-wide; a non-null value means the
asset has only ever been proved in one codebase and is not offered elsewhere.

## Providers

| name | what it is |
|---|---|
| `deepseek` | DeepSeek via the platform's existing `DeepSeekConnector` |
| `openai` | OpenAI chat completions, JSON mode |
| `scripted` | replays an authored response — a fixture, **not a model** |
| `null` | declares everything UNKNOWN, proposes nothing (the default) |

The default is `null` on purpose. A fresh environment must not start making paid API calls
because nobody chose a provider.

```
php artisan engineering:reason --providers
```

## UNKNOWN is a first-class answer

Anything a provider cannot justify must be the string `UNKNOWN`. An empty prose section is
rejected, because a blank reads downstream as an answer. Unknowns propagate to
IDENTIFY_RISKS, cap the ESTIMATE's confidence downward, and are quoted to the approver in
the blocking message.

## Commands

```
php artisan engineering:reason --providers          who can reason right now
php artisan engineering:reason --boundary           prove reasoning cannot write
php artisan engineering:reason --context=<uuid>     what would be sent, and what was left out
php artisan engineering:reason --task=<uuid>        reason, validate, store
php artisan engineering:reason --candidate=<uuid>   what was proposed, and whether it was accepted
```

Every proposal is stored in `engineering_candidates`, **including rejected ones**. Those
are the more valuable half: they are how "the provider keeps proposing writes to the test
config" becomes a visible pattern instead of a thing somebody half-remembers.

## What this engine does not do

It does not deploy, approve, verify, promote, or decide that a proposal is good enough to
skip a gate. Promotion still requires implementation evidence, and reasoning is not
implementation — LEARN records only what the workflow *observed* about a proposal, never
what the proposal *said* about itself.

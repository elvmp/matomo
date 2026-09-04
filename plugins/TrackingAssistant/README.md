# Tracking Assistant

Tracking Assistant is a Matomo plugin for reproducing, diagnosing and validating browser-based tracking problems. Playwright is an isolated implementation dependency executed by an asynchronous worker.

## First vertical slice

This branch implements the first deterministic broken-click workflow:

- queue and worker with leases/heartbeats
- Chromium runner in a fresh BrowserContext
- network/console/Matomo request observations without retaining request/response bodies
- SSRF and private-network protection in PHP and the runner
- deterministic scenario DSL; no arbitrary generated Playwright code
- native MTM draft inspection through a compatibility adapter
- Click Element `match_css_selector` diagnosis using browser evidence
- structured exact before/after proposal for one existing trigger selector
- explicit approval before draft mutation
- optimistic-concurrency hashes
- draft export safety snapshot
- native MTM trigger update only
- MTM Preview replay of the original scenario
- before/after regression checks
- automatic inverse rollback, with snapshot import as emergency recovery
- previous Preview state restoration

Live publishing is not implemented. Draft mutation is disabled by system setting by default.

## Runner setup

```bash
cd plugins/TrackingAssistant/playwright-runner
npm install
npx playwright install chromium
./console tracking-assistant:check-runner
./console tracking-assistant:worker
```

## Initial API flow

State-changing methods are POST-only.

```text
TrackingAssistant.startDiagnostic
TrackingAssistant.getDiagnostic
TrackingAssistant.getFindings
TrackingAssistant.getProposals
TrackingAssistant.approveProposal
TrackingAssistant.applyProposal
TrackingAssistant.getProposal
```

A first-slice scenario can assert an exact Matomo event count, for example `Donation / Click / CTA == 1` after clicking a deterministic locator.

## Security invariants

- `tracking_assistant_run` is included in Write/Admin roles
- diagnostic results require that capability
- selected MTM containers are checked through native Tag Manager APIs
- the async Playwright process never receives permanent Matomo authentication
- only HTTP(S) targets are allowed
- embedded URL credentials are rejected
- the target host must match the Matomo site or an approved testing domain
- private/reserved destinations are blocked unless explicitly enabled for intranet use
- no cookies, authorization headers, complete HTML, request bodies or response bodies are retained
- no LLM may directly execute MTM mutation APIs
- the first auto-fix may change exactly one existing Click Element CSS selector only
- native MTM permissions still gate the actual draft update
- publishing remains separate and is not implemented

## Validation status

Local checks completed for this branch:

- PHP syntax lint across the plugin and tests
- Node syntax validation for the Playwright runner
- JSON validation
- deterministic business-logic smoke test for missing event → selector proposal → simulated PASS

Browser-runtime E2E is still required on a real Matomo 5.10/5.12 installation. The current `elvmp/matomo` `5.x-dev` base is from January 2025, so sync/rebase it to the supported 5.12 line before treating integration results as release evidence.

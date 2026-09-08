---
starter_id: vanilla-php-cf
package_manager: composer
project_name: jk-bms-monitor
hints:
  language_family: php
  team_size: solo
  deployment_target: shared-hosting
  ci_provider: github-actions
  ci_default_flow: manual-promotion
  bootstrapper_confidence: best-effort
  path_taken: custom
  quality_override: true
  self_check_answers:
    typed: true
    from_official_starter: true
    conventions: true
    docs_current: true
    can_judge_agent: true
  has_auth: true
  has_payments: false
  has_realtime: false
  has_ai: false
  has_background_jobs: false
---

## Why this stack

Vanilla PHP 8.x picked deliberately to match the stack already running in other CyberFolks projects (reference: cvs-composite-valuation-score) — same shared-hosting target, same deploy mechanics, same operational muscle memory for a solo developer with a 3-week after-hours budget. `starter_id: vanilla-php-cf` is now a registered (but `best-effort`) card in the tech-stack-selector registry — added after this project's scaffold, using cvs-composite-valuation-score and this project as the two reference implementations; `/10x-bootstrapper` still cannot auto-generate the Core/routing/template boilerplate (only `composer init`), so manual copy from a reference project remains required. Quality gates typed/convention-based/popular-in-training are not formally met by "PHP without a framework" — compensation is explicit project structure and conventions documented in CLAUDE.md/AGENTS.md, mirroring the reference project. Auth is a single shared password (FR-004), no payments/realtime/AI/background jobs in MVP scope. Deploy: git push + manual git pull/FTP on shared hosting; CI runs checks on GitHub Actions with manual promotion, no auto-deploy pipeline for MVP. The ESP32 firmware is a separate, locally-installed component (Arduino/PlatformIO) and is out of scope for this web/hosting stack decision.

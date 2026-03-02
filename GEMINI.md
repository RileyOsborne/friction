# Friction - AI Development Guide

Before making changes to this codebase, read the documentation in `docs/`.

## Quick Reference

| Document | Purpose |
|----------|---------|
| `docs/GAME_RULES.md` | Scoring, turns, timers, doubles |
| `docs/ARCHITECTURE.md` | System design, two-tab pattern, real-time communication |
| `docs/DATA_MODELS.md` | Database structure and relationships |
| `docs/STATE_MACHINES.md` | Game/round state flow with diagrams |
| `docs/PLAYER_CONNECTIONS.md` | Multi-device support, heartbeats, connection handling |
| `docs/ANSWER_MATCHING.md` | Fuzzy matching algorithm details |
| `docs/DESIGN_DECISIONS.md` | Why things work the way they do |

## Key Files

**Core Logic:**
- `app/Services/GameStateMachine.php` - Central game flow orchestrator
- `app/Services/PlayerConnectionService.php` - Connection tracking

**State Definitions:**
- `app/Enums/GameStatus.php` - Game state enum
- `app/Enums/RoundStatus.php` - Round state enum

**Models:**
- `app/Models/Game.php` - Game configuration and state
- `app/Models/Round.php` - Round state and category
- `app/Models/Player.php` - Player data and connection
- `app/Models/Answer.php` - Answer definitions
- `app/Models/Category.php` - Question categories

**Views:**
- `resources/views/livewire/pages/games/control.blade.php` - GM control panel
- `resources/views/games/present.blade.php` - Presentation display
- `resources/views/livewire/pages/player/play.blade.php` - Player device

## Before Making Changes

1. Read the relevant documentation section
2. Understand the state machine if modifying game flow
3. Check if changes affect both Control and Presentation views
4. Test with multiple players if touching connection logic

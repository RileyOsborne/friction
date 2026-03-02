# Design Decisions

This document explains the reasoning behind key architectural and implementation choices in Friction.

## Two-Tab Architecture

### Decision
The GM runs two separate browser tabs: Control and Presentation.

### Why Not Single Page?
- **Information separation**: GM needs to see all answers; players should only see revealed answers
- **Input hiding**: GM enters player answers without showing keyboard/autocomplete to audience
- **Dramatic control**: GM controls exact timing of reveals for suspense
- **Focus management**: Control tab can have forms/inputs without affecting display

### Why BroadcastChannel?
- **Zero latency**: Instant communication between tabs
- **No network**: Works offline, no server round-trip
- **Simple**: No additional infrastructure needed
- **Reliable**: Browser-native API, no connection management

### Trade-offs
- GM must be physically present (local machine)
- Cannot have remote GM (would need WebSocket fallback)

## Centralized GameStateMachine

### Decision
All game state changes flow through a single `GameStateMachine` service.

### Why?
- **Single source of truth**: Prevents inconsistent state across views
- **Validation**: One place to enforce transition rules
- **Broadcasting**: All state changes automatically broadcast
- **Testability**: Easy to test state transitions in isolation
- **Audit trail**: Single point for logging/debugging

### Alternative Considered
Direct model updates from controllers - rejected because:
- Risk of inconsistent state
- Duplicate broadcasting logic
- No transition validation

## Player Session with Heartbeats

### Decision
Track player connections via session tokens and periodic heartbeats.

### Why Not WebSocket Connection State?
- **Reliability**: Network connections drop unexpectedly
- **Graceful degradation**: Game continues if player loses connection
- **Reconnection**: Player can rejoin from different device/tab
- **GM takeover**: Disconnected players don't block gameplay

### Heartbeat Interval (5s) and Timeout (15s)
- **5s interval**: Frequent enough to detect disconnects quickly
- **15s timeout**: 3x interval allows for network hiccups
- **Balance**: Quick detection vs. unnecessary disconnects

## Position-Based Scoring

### Decision
Points equal position number for #1-10, fixed penalty for #11+.

### Why?
- **Original format**: Based on the TV show's scoring system
- **Risk/reward**: Higher positions are harder to guess but worth more
- **Drama**: Creates friction when approaching #10
- **Simplicity**: Easy for players to understand

### Why Fixed Friction Penalty (-5)?
- **Dramatic cliff**: Clear boundary at #10
- **Risk deterrent**: Discourages wild guesses
- **Simplicity**: One penalty value to remember

## Turn Order Rotation

### Decision
First player rotates each round.

### Why?
- **Fairness**: First player has time pressure (countdown)
- **Balance**: Each player goes first equally over the game
- **Strategy**: Later players can react to earlier answers
- **Formula**: Simple `(round - 1) % playerCount` offset

## Fuzzy Answer Matching

### Decision
Use multi-strategy fuzzy matching instead of exact match only.

### Why?
- **User experience**: Players shouldn't be penalized for "The Beatles" vs "Beatles"
- **Typo tolerance**: Minor spelling errors shouldn't cost points
- **Speed**: GM doesn't need to correct every variation

### Why Not More Aggressive Fuzzy Matching?
- **False positives**: Don't want "Paris" matching "Paradise"
- **GM control**: GM can always override matches
- **Balance**: Reward players who know exact answers

## Confirmation Modals (Not Browser Dialogs)

### Decision
Use styled, in-page confirmation modals instead of browser-native `confirm()` dialogs or `wire:confirm`.

### Why?
- **Consistent design**: Matches the application's dark theme and styling
- **Better UX**: More context can be shown (warnings, additional info)
- **Mobile-friendly**: Browser dialogs vary across devices
- **Branded experience**: Feels part of the app, not a system interruption
- **Accessibility**: Better keyboard navigation and screen reader support

### Implementation Pattern
```php
// In Livewire component:
public bool $showDeleteModal = false;
public ?string $itemToDelete = null;

public function confirmDelete(string $id): void
{
    $this->itemToDelete = $id;
    $this->showDeleteModal = true;
}

public function cancelDelete(): void
{
    $this->showDeleteModal = false;
    $this->itemToDelete = null;
}

public function deleteItem(): void
{
    if (!$this->itemToDelete) return;
    // ... delete logic
    $this->cancelDelete();
}
```

```html
<!-- In Blade template: -->
@if($showDeleteModal)
    <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center p-4">
            <div class="fixed inset-0 bg-black/70" wire:click="cancelDelete"></div>
            <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-red-400">Delete Item</h3>
                    <button wire:click="cancelDelete" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                </div>
                <div class="p-6">
                    <p class="text-slate-300 mb-6">Are you sure? This cannot be undone.</p>
                    <div class="flex justify-end gap-3">
                        <button wire:click="cancelDelete" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg">Cancel</button>
                        <button wire:click="deleteItem" class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg">Delete</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
```

### When to Show Confirmation
- **Always**: Destructive actions (delete, remove, reset)
- **Conditional**: Replacing existing data (only if data exists)
- **Never**: Non-destructive actions (create, view, navigate)

## ULIDs Instead of Auto-Increment IDs

### Decision
Use ULIDs (Universally Unique Lexicographically Sortable Identifiers) for all models.

### Why?
- **No enumeration**: Can't guess other game/player IDs
- **Sortable**: Natural ordering by creation time
- **Distributed-ready**: No conflicts in multi-server setup
- **URL-safe**: Can be used in routes without encoding

## SQLite Database

### Decision
Use SQLite for data storage.

### Why?
- **Simplicity**: No separate database server
- **Portability**: Single file, easy backup/restore
- **Performance**: More than sufficient for single-machine use
- **Development**: Same database in dev and production

### When to Migrate to PostgreSQL/MySQL?
- Multi-server deployment
- High concurrent write load
- Need for advanced SQL features

## GM-Created vs Self-Registered Players

### Decision
Support both GM-created player slots and self-registration.

### Why Both?
- **Flexibility**: GM can pre-create players or let them join
- **Claiming**: Players can claim GM-created slots
- **Hybrid**: Mix of both in same game

### Why GM-Created "Always Connected"?
- **Simplicity**: No session tracking needed
- **GM control**: GM manually enters their answers anyway
- **No confusion**: GM always knows they're controlling these players

## Current Assumptions

These assumptions may need revisiting for future features:

### GM Always Local
- Two-tab architecture assumes same machine
- BroadcastChannel only works locally

### Single Active Game
- No support for GM running multiple simultaneous games
- Would need GM authentication and game switching

### In-Person Play
- Designed for everyone in same room
- Remote play would need different reveal timing

## Button and Action Color Guide

### Decision
Use consistent color semantics across all buttons and actions to create clear visual language.

### Color Roles

| Purpose | Solid Button | Outline/Ghost | Inline Link |
|---------|-------------|---------------|-------------|
| **Primary Action** | `bg-blue-600 hover:bg-blue-700` | - | `text-blue-400 hover:text-blue-300` |
| **Success/Go** | `bg-green-600 hover:bg-green-700` | - | `text-green-400 hover:text-green-300` |
| **Feature/Special** | `bg-purple-600 hover:bg-purple-700` | - | `text-purple-400 hover:text-purple-300` |
| **Secondary** | `bg-slate-700 hover:bg-slate-600` | - | `text-slate-400 hover:text-slate-300` |
| **Destructive** | `bg-red-600 hover:bg-red-700` | `bg-red-600/20 text-red-400 border-red-600/50` | `text-red-400 hover:text-red-300` |

### When to Use Each

**Primary Action (Blue)**
- Creating new items: "New Game", "New Category", "Add Player"
- Form submissions: "Create", "Save", "Update"
- Navigation to next step: "Continue Game"
- Non-destructive confirmations: "Import", "Randomize All"

**Success/Go (Green)**
- Starting gameplay: "Start Game", "Play"
- Proceeding in game flow: "Begin Round", "Reveal Scores"
- Positive state changes

**Feature/Special (Purple)**
- Special modes: "Open Presentation"
- Premium or highlighted features

**Secondary (Slate)**
- Cancel buttons in modals
- Alternative options: "Import Starter Pack" (when showing as secondary choice)
- Utility actions: "Randomize All" (small utility button)

**Destructive (Red)**
- Use **inline text** (`text-red-400`) for: Delete/Remove links in lists
- Use **outline button** for: Destructive actions that aren't the primary page action (e.g., "Remove Starter Pack")
- Use **solid button** for: Modal confirmation buttons ("Delete Game", "Reset")

### Why Not Red for Create Actions?
Red is strongly associated with danger/destruction in UI patterns. Using red for "New Game" creates cognitive dissonance. Blue provides a neutral, professional call-to-action that doesn't conflict with destructive action semantics.

### Implementation Pattern
```html
<!-- Primary action button -->
<button class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold transition">
    + New Game
</button>

<!-- Destructive outline button (for secondary destructive actions) -->
<button class="bg-red-600/20 hover:bg-red-600/30 text-red-400 border border-red-600/50 px-4 py-3 rounded-lg font-semibold transition">
    Remove Starter Pack
</button>

<!-- Destructive solid button (for modal confirmations) -->
<button class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-medium transition">
    Delete Game
</button>

<!-- Inline destructive link -->
<button class="text-red-400 hover:text-red-300 font-medium">
    Delete
</button>
```

## Future Considerations

### Remote GM Play
Would require:
- Move presentation state to WebSockets
- Add GM authentication
- Handle network latency for reveals
- Consider offline fallback

### Spectator Mode
Would need:
- Read-only view type
- Filter unrevealed answers from state
- Separate spectator count tracking

### Team Play
Would require:
- Team model with player relationships
- Team-based turn order
- Combined team scoring
- Team answer submission (one per team)

### Category Packs / Themes
Would benefit from:
- Pack/theme grouping for categories
- Difficulty ratings
- Topic tagging

### Scoring Variants
Could support:
- Alternative scoring formulas
- Bonus rounds
- Time-based multipliers

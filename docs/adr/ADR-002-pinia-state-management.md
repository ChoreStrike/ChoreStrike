# ADR-002: Pinia for State Management

**Status**: Accepted  
**Date**: 2026-02-24  
**Deciders**: Development Team

## Context

ChoreStrike requires centralized state management for handling houses, rooms, tasks, game state, and user data across multiple components. We need a state management solution that integrates well with Vue 3 and provides good developer experience.

## Decision

We will use **Pinia** as the state management library for ChoreStrike.

## Consequences

### Positive

- **Official Vue Recommendation**: Pinia is the officially recommended state management solution for Vue 3
- **Composition API Native**: Designed from the ground up for Vue 3 Composition API
- **Better TypeScript Support**: Excellent type inference without manual type annotations
- **Simpler API**: No mutations, just actions - more intuitive than Vuex
- **Modular by Design**: Each store is independent, making code splitting easier
- **DevTools Integration**: Full support for Vue DevTools with time-travel debugging
- **Smaller Bundle Size**: ~1KB vs Vuex's ~3KB
- **Hot Module Replacement**: Better HMR support during development

### Negative

- **Newer Library**: Less mature than Vuex, though officially supported
- **Migration Path**: If we ever need Vuex-specific features, migration would be required
- **Learning Resources**: Fewer tutorials and examples compared to Vuex (though growing rapidly)

### Neutral

- **Store Organization**: Need to establish conventions for organizing stores (by feature vs by data type)
- **Action Patterns**: Must define patterns for async actions and error handling

## Alternatives Considered

### Vuex 4
- **Pros**: More mature, larger ecosystem, more learning resources
- **Cons**: More boilerplate, weaker TypeScript support, mutations add complexity, not the recommended solution for new Vue 3 projects
- **Rejected**: Pinia is the official successor and provides better DX

### Plain Reactive Objects
- **Pros**: No additional dependencies, simple for small apps
- **Cons**: No DevTools integration, no plugin system, harder to organize at scale, no SSR support
- **Rejected**: Insufficient for a complex app with multiple data domains

### Composables Only
- **Pros**: Minimal abstraction, uses native Vue features
- **Cons**: No centralized state, harder to share state across unrelated components, no time-travel debugging
- **Rejected**: Not suitable for global state management

## Implementation Notes

### Store Structure
We organize stores by domain:
- `house.js` - House data and health
- `rooms.js` - Floors and rooms
- `tasks.js` - Task templates and definitions
- `roomTasks.js` - Room-task assignments
- `game.js` - Game state, rewards, combos, medals
- `user.js` - Current user and authentication

### Patterns
- All API calls are made in store actions
- Stores expose getters for computed/derived data
- Loading and error states are managed within stores
- Actions return promises for component-level error handling

## References

- [Pinia Documentation](https://pinia.vuejs.org/)
- [Why Pinia is the new Vuex](https://vuejs.org/guide/scaling-up/state-management.html)
- Project: `src/store/` directory

# ADR-001: Use Vue 3 Composition API

**Status**: Accepted  
**Date**: 2026-02-24  
**Deciders**: Development Team

## Context

ChoreStrike is a Nextcloud app that requires a modern, reactive frontend framework. We need to decide between Vue 3's Options API and Composition API for component development. The choice will affect code organization, reusability, and maintainability throughout the project.

## Decision

We will use **Vue 3 Composition API** as the primary pattern for all Vue components in ChoreStrike.

## Consequences

### Positive

- **Better Code Organization**: Related logic can be grouped together using composables, rather than being split across `data`, `methods`, `computed`, etc.
- **Improved Reusability**: Logic can be extracted into reusable composable functions that can be shared across components
- **Better TypeScript Support**: Composition API provides superior type inference compared to Options API
- **Easier Testing**: Pure functions (composables) are easier to test in isolation
- **Alignment with Nextcloud Ecosystem**: Nextcloud Vue components are moving towards Composition API
- **Future-Proof**: Composition API is the recommended approach for new Vue 3 projects

### Negative

- **Learning Curve**: Team members familiar with Vue 2 Options API need to learn new patterns
- **More Boilerplate**: Requires explicit `ref()` and `reactive()` declarations
- **Setup Function Complexity**: Large components can have lengthy setup functions if not properly organized

### Neutral

- **Consistency Required**: Team must commit to using Composition API consistently across all components
- **Composable Patterns**: Need to establish conventions for organizing and naming composables

## Alternatives Considered

### Options API
- **Pros**: More familiar to Vue 2 developers, less boilerplate for simple components
- **Cons**: Poor code organization for complex components, harder to extract reusable logic, weaker TypeScript support
- **Rejected**: Does not scale well for complex components and limits code reusability

### Class-Based Components
- **Pros**: Familiar to developers from OOP backgrounds
- **Cons**: Not officially supported in Vue 3, requires additional dependencies, less idiomatic
- **Rejected**: Not part of Vue 3's core design philosophy

## References

- [Vue 3 Composition API Documentation](https://vuejs.org/guide/extras/composition-api-faq.html)
- [Nextcloud Vue Components](https://github.com/nextcloud/nextcloud-vue)
- Project: `src/views/Dashboard.vue`, `src/components/Dashboard/FloorList.vue`

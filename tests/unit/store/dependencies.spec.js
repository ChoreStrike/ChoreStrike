/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { readFileSync, readdirSync } from 'fs'
import { join, dirname } from 'path'

const STORE_DIR = join(__dirname, '../../../src/store')

/**
 * Parse the static store→store import edges of every Pinia store module
 * (CS-PLAN-067 §3.1 D3 pin: the store dependency graph must stay a DAG —
 * a cycle would make Pinia's use*() initialization order load-bearing and
 * reintroduce the multi-store refresh coupling the fan-out removal was
 * meant to delete).
 *
 * @return {Map<string, Set<string>>} store name → imported store names
 */
function storeGraph() {
	const files = readdirSync(STORE_DIR).filter(f => f.endsWith('.js'))
	const graph = new Map()

	for (const file of files) {
		const name = file.replace(/\.js$/, '')
		const content = readFileSync(join(STORE_DIR, file), 'utf8')
		const imports = new Set()
		// Store modules import each other via `./x.js`; views via `../store/x.js`.
		const importPattern = /from\s+'(?:\.\/|\.\.\/store\/)([a-zA-Z]+)\.js'/g
		let match
		while ((match = importPattern.exec(content)) !== null) {
			if (match[1] !== name) {
				imports.add(match[1])
			}
		}
		graph.set(name, imports)
	}

	return graph
}

function hasCycle(node, graph, visiting, visited) {
	if (visiting.has(node)) return true
	if (visited.has(node)) return false
	visiting.add(node)
	for (const dep of graph.get(node) ?? []) {
		if (hasCycle(dep, graph, visiting, visited)) return true
	}
	visiting.delete(node)
	visited.add(node)
	return false
}

describe('store dependency graph (CS-PLAN-067 §3.1 D3 pin)', () => {
	const graph = storeGraph()

	it('discovers the expected store modules', () => {
		for (const expected of ['roomTasks', 'tasks', 'rooms', 'user', 'house']) {
			expect(graph.has(expected)).toBe(true)
		}
	})

	it('is acyclic — no store may (transitively) import itself', () => {
		const visited = new Set()
		for (const node of graph.keys()) {
			expect(hasCycle(node, graph, new Set(), visited)).toBe(false)
		}
	})

	it('pins the documented edges (the DAG shape is load-bearing)', () => {
		// roomTasks reads rooms/tasks/user projections; refreshDerivedProjections
		// fans out to scheduling/minigame/titles/house.
		const edges = graph.get('roomTasks')
		expect(edges).toContain('rooms')
		expect(edges).toContain('tasks')
		expect(edges).toContain('user')
	})

	it('keeps roomTasks free of the scheduling cycle class', () => {
		// scheduling depends on roomTasks; roomTasks must never import
		// scheduling back (the derived-projection fan-out is one-directional).
		expect(graph.get('roomTasks')).not.toContain('scheduling')
		expect(graph.get('scheduling')).toContain('roomTasks')
	})
})

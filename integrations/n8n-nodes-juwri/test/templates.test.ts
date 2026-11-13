// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const templatesDir = fileURLToPath(new URL('../templates', import.meta.url));
const files = readdirSync(templatesDir).filter((f) => f.endsWith('.json'));

interface WfNode {
	name: string;
	type: string;
	typeVersion: number;
	parameters?: Record<string, unknown>;
}
interface Workflow {
	name: string;
	nodes: WfNode[];
	connections: Record<string, { main?: Array<Array<{ node: string }>> }>;
}

function load(file: string): Workflow {
	return JSON.parse(readFileSync(`${templatesDir}/${file}`, 'utf8')) as Workflow;
}

describe('n8n workflow templates', () => {
	it('ships at least the six documented example workflows', () => {
		expect(files.length).toBeGreaterThanOrEqual(6);
	});

	it.each(files)('%s is a well-formed, importable workflow', (file) => {
		const wf = load(file);

		// Shape n8n expects on import.
		expect(typeof wf.name).toBe('string');
		expect(wf.name.length).toBeGreaterThan(0);
		expect(Array.isArray(wf.nodes)).toBe(true);
		expect(wf.nodes.length).toBeGreaterThan(0);
		expect(typeof wf.connections).toBe('object');

		// Every node is complete and uniquely named.
		const names = new Set<string>();
		for (const node of wf.nodes) {
			expect(typeof node.name).toBe('string');
			expect(typeof node.type).toBe('string');
			expect(typeof node.typeVersion).toBe('number');
			expect(names.has(node.name)).toBe(false);
			names.add(node.name);
		}

		// Uses at least one juwri node (the point of the template).
		expect(wf.nodes.some((n) => n.type.startsWith('n8n-nodes-juwri.'))).toBe(true);

		// Carries a sticky note with setup steps (template-library convention).
		expect(wf.nodes.some((n) => n.type === 'n8n-nodes-base.stickyNote')).toBe(true);

		// Every connection references only nodes that exist.
		for (const [source, conn] of Object.entries(wf.connections)) {
			expect(names.has(source)).toBe(true);
			for (const outputs of conn.main ?? []) {
				for (const target of outputs) {
					expect(names.has(target.node)).toBe(true);
				}
			}
		}
	});

	it('every template references a real juwri node type and (for triggers) a real event', () => {
		const validEvents = new Set([
			'*',
			'order.created',
			'order.status_changed',
			'order.paid',
			'refund.completed',
			'reservation.created',
			'reservation.cancelled',
			'customer.created',
			'shift.opened',
			'shift.closed',
			'menu.updated',
			'entitlement.changed',
			'subscription.paused',
			'subscription.resumed',
			'subscription.renewed',
			'subscription.expired',
			'subscription.plan_changed',
			'subscription.past_due',
			'subscription.expiring',
			'subscription.trial_ending',
			'app.uninstalled',
		]);
		const validTypes = new Set([
			'n8n-nodes-juwri.juwri',
			'n8n-nodes-juwri.juwriTrigger',
		]);

		for (const file of files) {
			const wf = load(file);
			for (const node of wf.nodes) {
				if (!node.type.startsWith('n8n-nodes-juwri.')) continue;
				expect(validTypes.has(node.type)).toBe(true);
				if (node.type === 'n8n-nodes-juwri.juwriTrigger') {
					const events = (node.parameters?.events as string[]) ?? [];
					expect(events.length).toBeGreaterThan(0);
					for (const ev of events) {
						expect(validEvents.has(ev)).toBe(true);
					}
				}
			}
		}
	});
});

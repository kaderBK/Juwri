// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Build helper (dev-only, Node built-ins only — keeps the package at ZERO
 * runtime dependencies). tsc emits only .js/.d.ts; n8n also needs each node's
 * codex `*.node.json` metadata and its `.svg` icon beside the compiled node.
 * This copies those non-TS assets from nodes/** into dist/nodes/** preserving
 * the directory layout.
 */
import { readdirSync, statSync, mkdirSync, copyFileSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url)) + '/..';
const srcRoot = join(root, 'nodes');
const outRoot = join(root, 'dist', 'nodes');
const ASSET_EXT = ['.json', '.svg', '.png'];

function walk(dir) {
	for (const entry of readdirSync(dir)) {
		const full = join(dir, entry);
		if (statSync(full).isDirectory()) {
			walk(full);
			continue;
		}
		if (!ASSET_EXT.some((ext) => full.endsWith(ext))) continue;
		const rel = relative(srcRoot, full);
		const dest = join(outRoot, rel);
		mkdirSync(dirname(dest), { recursive: true });
		copyFileSync(full, dest);
		console.log('copied', rel);
	}
}

walk(srcRoot);

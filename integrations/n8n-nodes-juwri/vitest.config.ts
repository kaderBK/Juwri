// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		include: ['test/**/*.test.ts'],
		environment: 'node',
		globals: false,
	},
});

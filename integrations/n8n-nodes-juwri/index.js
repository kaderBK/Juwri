// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * n8n discovers nodes and credentials through the `n8n` attribute in
 * package.json (the compiled paths under dist/), not through this file. It
 * exists only so the package has a valid CommonJS entry point.
 */
module.exports = {};

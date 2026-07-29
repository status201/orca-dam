#!/usr/bin/env node
// `npm run e2e:reset` — rebuild database/e2e.sqlite and reseed the fixtures.
import { reseed } from './db.js';

try {
    await reseed();
    process.stdout.write('e2e: database/e2e.sqlite rebuilt and seeded.\n');
} catch (error) {
    process.stderr.write(`e2e reseed failed: ${error.message}\n${error.stdout || ''}${error.stderr || ''}\n`);
    process.exit(1);
}

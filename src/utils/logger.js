/**
 * Minimal leveled logger. Swap for pino/winston later without touching call sites.
 */
const levels = ['debug', 'info', 'warn', 'error'];

function log(level, ...args) {
  const ts = new Date().toISOString();
  // eslint-disable-next-line no-console
  console[level === 'debug' ? 'log' : level](`[${ts}] [${level.toUpperCase()}]`, ...args);
}

module.exports = levels.reduce((acc, level) => {
  acc[level] = (...args) => log(level, ...args);
  return acc;
}, {});

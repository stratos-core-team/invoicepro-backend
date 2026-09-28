const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * "NGN 86,000.00". Uses the currency code rather than the Naira sign because
 * the standard PDF fonts don't contain the U+20A6 glyph. Amounts arrive as
 * strings from Postgres numeric columns, hence the Number() conversion.
 */
function formatMoney(amount, currency = 'NGN') {
  const n = Number(amount);
  if (!Number.isFinite(n)) return `${currency} 0.00`;
  const [whole, decimals] = Math.abs(n).toFixed(2).split('.');
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return `${n < 0 ? '-' : ''}${currency} ${grouped}.${decimals}`;
}

/** "15 Jan 2026" from a 'YYYY-MM-DD' string or a Date. Never shifts the day across timezones. */
function formatDate(value) {
  if (!value) return '';

  let year;
  let month;
  let day;

  if (typeof value === 'string') {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
    if (!match) return value;
    year = Number(match[1]);
    month = Number(match[2]);
    day = Number(match[3]);
  } else if (value instanceof Date) {
    year = value.getFullYear();
    month = value.getMonth() + 1;
    day = value.getDate();
  } else {
    return String(value);
  }

  return `${day} ${MONTHS[month - 1]} ${year}`;
}

/** "1.00" -> "1", "3.50" -> "3.5", "3.333" -> "3.333" */
function formatQuantity(quantity) {
  const n = Number(quantity);
  return Number.isFinite(n) ? String(n) : String(quantity ?? '');
}

module.exports = { formatMoney, formatDate, formatQuantity };

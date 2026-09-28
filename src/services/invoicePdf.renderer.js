const PDFDocument = require('pdfkit');
const { formatMoney, formatDate, formatQuantity } = require('../utils/formatters');

/**
 * Pure renderer: plain objects in, PDF Buffer out. No DB, no env, no I/O
 * beyond registering optional font files, which keeps it easy to test.
 *
 * Design brief from the PRD: clean, print-ready, itemized, neutral colours plus
 * one accent, "finance clarity, not visual noise".
 */

const COLORS = {
  text: '#1F2937',
  muted: '#6B7280',
  line: '#E5E7EB',
  accent: '#0F766E',
  headerBg: '#F3F4F6',
};

const MARGIN = 50;
const CONTENT_WIDTH = 495; // A4 (595pt) minus 2 * 50pt margins
const RIGHT_COL_X = 350;

const COLS = {
  description: { x: MARGIN, width: 240 },
  quantity: { x: 300, width: 50 },
  unitPrice: { x: 355, width: 90 },
  amount: { x: 450, width: 95 },
};

// ---------- low-level helpers ----------

function put(ctx, str, x, y, opts = {}) {
  const { bold = false, size = 10, color = COLORS.text, width, align = 'left', lineBreak } = opts;
  ctx.doc.font(bold ? ctx.fonts.bold : ctx.fonts.regular).fontSize(size).fillColor(color);
  const textOptions = { align };
  if (width !== undefined) textOptions.width = width;
  if (lineBreak !== undefined) textOptions.lineBreak = lineBreak;
  ctx.doc.text(String(str ?? ''), x, y, textOptions);
}

function measure(ctx, str, { bold = false, size = 10, width }) {
  ctx.doc.font(bold ? ctx.fonts.bold : ctx.fonts.regular).fontSize(size);
  return ctx.doc.heightOfString(String(str ?? ''), { width });
}

function hLine(ctx, y, color = COLORS.line) {
  ctx.doc
    .moveTo(MARGIN, y)
    .lineTo(MARGIN + CONTENT_WIDTH, y)
    .lineWidth(0.75)
    .strokeColor(color)
    .stroke();
}

/** Lowest y that content may reach, leaving room for the footer. */
function bottomLimit(ctx) {
  return ctx.doc.page.height - MARGIN - 30;
}

/** Starts a new page if `needed` points don't fit below `y`; returns the y to continue from. */
function ensureSpace(ctx, y, needed) {
  if (y + needed <= bottomLimit(ctx)) return y;
  ctx.doc.addPage();
  return MARGIN;
}

// ---------- sections ----------

function drawWatermark(ctx) {
  const { doc } = ctx;
  const { width, height } = doc.page;
  doc.save();
  doc.rotate(-45, { origin: [width / 2, height / 2] });
  doc
    .font(ctx.fonts.bold)
    .fontSize(64)
    .fillColor('#000000')
    .fillOpacity(0.06)
    .text('InvoicePro NG', 0, height / 2 - 32, { width, align: 'center', lineBreak: false });
  doc.restore();
}

function drawHeader(ctx, invoice, business) {
  let y = MARGIN;

  put(ctx, business.name, MARGIN, y, { bold: true, size: 18, width: 290 });
  put(ctx, 'INVOICE', RIGHT_COL_X, y, {
    bold: true,
    size: 22,
    color: COLORS.accent,
    width: 195,
    align: 'right',
  });
  put(ctx, invoice.invoice_number, RIGHT_COL_X, y + 28, {
    size: 11,
    color: COLORS.muted,
    width: 195,
    align: 'right',
  });

  y += measure(ctx, business.name, { bold: true, size: 18, width: 290 }) + 4;

  for (const line of [business.email, business.phone, business.address].filter(Boolean)) {
    put(ctx, line, MARGIN, y, { size: 9, color: COLORS.muted, width: 290 });
    y += measure(ctx, line, { size: 9, width: 290 }) + 2;
  }

  y = Math.max(y, MARGIN + 50) + 8;
  hLine(ctx, y);
  return y + 18;
}

function drawParties(ctx, invoice, startY) {
  let leftY = startY;
  put(ctx, 'BILL TO', MARGIN, leftY, { bold: true, size: 8, color: COLORS.muted });
  leftY += 14;

  put(ctx, invoice.customer_name, MARGIN, leftY, { bold: true, size: 11, width: 280 });
  leftY += measure(ctx, invoice.customer_name, { bold: true, size: 11, width: 280 }) + 3;

  for (const line of [invoice.customer_email, invoice.customer_phone, invoice.customer_address].filter(Boolean)) {
    put(ctx, line, MARGIN, leftY, { size: 9, color: COLORS.muted, width: 280 });
    leftY += measure(ctx, line, { size: 9, width: 280 }) + 2;
  }

  let rightY = startY;
  const rows = [
    ['Issue date', formatDate(invoice.issue_date)],
    ['Due date', formatDate(invoice.due_date)],
    ['Status', String(invoice.status).toUpperCase()],
  ];
  for (const [label, value] of rows) {
    put(ctx, label, RIGHT_COL_X, rightY, { size: 9, color: COLORS.muted, width: 90 });
    put(ctx, value, RIGHT_COL_X + 90, rightY, { bold: true, size: 9, width: 105, align: 'right' });
    rightY += 16;
  }

  return Math.max(leftY, rightY) + 24;
}

function drawTableHeader(ctx, y) {
  ctx.doc.rect(MARGIN, y, CONTENT_WIDTH, 22).fill(COLORS.headerBg);
  const textY = y + 7;
  const style = { bold: true, size: 8, color: COLORS.muted };
  put(ctx, 'DESCRIPTION', COLS.description.x + 6, textY, { ...style, width: COLS.description.width - 6 });
  put(ctx, 'QTY', COLS.quantity.x, textY, { ...style, width: COLS.quantity.width, align: 'right' });
  put(ctx, 'UNIT PRICE', COLS.unitPrice.x, textY, { ...style, width: COLS.unitPrice.width, align: 'right' });
  put(ctx, 'AMOUNT', COLS.amount.x, textY, { ...style, width: COLS.amount.width, align: 'right' });
  return y + 22 + 8;
}

function drawItemsTable(ctx, invoice, startY) {
  let y = drawTableHeader(ctx, startY);

  for (const item of invoice.items) {
    const descWidth = COLS.description.width - 6;
    const descHeight = measure(ctx, item.description, { size: 10, width: descWidth });
    const rowHeight = Math.max(descHeight, 12) + 12;

    if (y + rowHeight > bottomLimit(ctx)) {
      ctx.doc.addPage();
      y = drawTableHeader(ctx, MARGIN);
    }

    put(ctx, item.description, COLS.description.x + 6, y, { size: 10, width: descWidth });
    put(ctx, formatQuantity(item.quantity), COLS.quantity.x, y, {
      size: 10,
      width: COLS.quantity.width,
      align: 'right',
      lineBreak: false,
    });
    put(ctx, formatMoney(item.unit_price, invoice.currency), COLS.unitPrice.x, y, {
      size: 10,
      width: COLS.unitPrice.width,
      align: 'right',
      lineBreak: false,
    });
    put(ctx, formatMoney(item.line_total, invoice.currency), COLS.amount.x, y, {
      size: 10,
      width: COLS.amount.width,
      align: 'right',
      lineBreak: false,
    });

    y += rowHeight;
    hLine(ctx, y - 6);
  }

  return y + 8;
}

function drawTotals(ctx, invoice, startY) {
  let y = ensureSpace(ctx, startY, 90);
  const labelX = RIGHT_COL_X;
  const labelWidth = 80;
  const valueX = 430;
  const valueWidth = 115;

  const row = (label, value, opts = {}) => {
    const size = opts.size || 10;
    put(ctx, label, labelX, y, { size, bold: opts.bold, color: opts.labelColor || COLORS.muted, width: labelWidth });
    put(ctx, value, valueX, y, {
      size,
      bold: opts.bold,
      color: opts.valueColor || COLORS.text,
      width: valueWidth,
      align: 'right',
      lineBreak: false,
    });
    y += opts.gap || 18;
  };

  row('Subtotal', formatMoney(invoice.subtotal, invoice.currency));
  if (Number(invoice.tax_amount) > 0) {
    row('Tax', formatMoney(invoice.tax_amount, invoice.currency));
  }

  ctx.doc
    .moveTo(labelX, y)
    .lineTo(MARGIN + CONTENT_WIDTH, y)
    .lineWidth(0.75)
    .strokeColor(COLORS.line)
    .stroke();
  y += 8;

  row('Total', formatMoney(invoice.total_amount, invoice.currency), {
    bold: true,
    size: 12,
    labelColor: COLORS.text,
    valueColor: COLORS.accent,
    gap: 24,
  });

  return y + 10;
}

function drawNotes(ctx, invoice, startY) {
  let y = startY;
  const sections = [
    ['PAYMENT TERMS', invoice.payment_terms],
    ['NOTES', invoice.notes],
  ].filter(([, body]) => body);

  for (const [label, body] of sections) {
    const bodyHeight = measure(ctx, body, { size: 9, width: CONTENT_WIDTH });
    y = ensureSpace(ctx, y, bodyHeight + 30);
    put(ctx, label, MARGIN, y, { bold: true, size: 8, color: COLORS.muted });
    y += 13;
    put(ctx, body, MARGIN, y, { size: 9, width: CONTENT_WIDTH });
    y += bodyHeight + 16;
  }

  return y;
}

function drawFooterBranding(ctx) {
  const { doc } = ctx;
  // Drawing inside the bottom margin would otherwise make pdfkit start a new page.
  doc.page.margins.bottom = 0;
  put(ctx, 'Created with InvoicePro NG - upgrade to Pro to remove this branding', MARGIN, doc.page.height - 35, {
    size: 8,
    color: COLORS.muted,
    width: CONTENT_WIDTH,
    align: 'center',
    lineBreak: false,
  });
}

function resolveFonts(doc, fonts) {
  if (fonts && fonts.regular && fonts.bold) {
    doc.registerFont('Body', fonts.regular);
    doc.registerFont('Body-Bold', fonts.bold);
    return { regular: 'Body', bold: 'Body-Bold' };
  }
  return { regular: 'Helvetica', bold: 'Helvetica-Bold' };
}

/**
 * @param {object} args
 * @param {object} args.invoice  invoice row (snake_case) incl. items[], customer_name/email/phone/address
 * @param {object} args.business { name, email, phone, address }
 * @param {boolean} args.watermark true for Free plan (diagonal watermark + footer branding)
 * @param {{regular?: string, bold?: string}} [args.fonts] optional TTF paths for full Unicode support
 * @returns {Promise<Buffer>}
 */
function renderInvoicePdf({ invoice, business, watermark, fonts }) {
  return new Promise((resolve, reject) => {
    try {
      const doc = new PDFDocument({
        size: 'A4',
        margin: MARGIN,
        info: { Title: `Invoice ${invoice.invoice_number}`, Author: business.name },
      });

      const chunks = [];
      doc.on('data', (chunk) => chunks.push(chunk));
      doc.on('end', () => resolve(Buffer.concat(chunks)));
      doc.on('error', reject);

      const ctx = { doc, fonts: resolveFonts(doc, fonts) };

      if (watermark) {
        drawWatermark(ctx);
        doc.on('pageAdded', () => drawWatermark(ctx));
      }

      let y = drawHeader(ctx, invoice, business);
      y = drawParties(ctx, invoice, y);
      y = drawItemsTable(ctx, invoice, y);
      y = drawTotals(ctx, invoice, y);
      drawNotes(ctx, invoice, y);

      if (watermark) drawFooterBranding(ctx);

      doc.end();
    } catch (err) {
      reject(err);
    }
  });
}

module.exports = { renderInvoicePdf };

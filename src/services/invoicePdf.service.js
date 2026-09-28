const fs = require('fs');
const env = require('../config/env');
const AppError = require('../utils/AppError');
const invoicesRepo = require('../repositories/invoices.repository');
const usersRepo = require('../repositories/users.repository');
const { renderInvoicePdf } = require('./invoicePdf.renderer');

/**
 * Optional Unicode fonts. The built-in PDF fonts can't draw characters like
 * the Yoruba subdotted letters in names (e.g. "Ọlá", "Adéṣọlá"). Point
 * PDF_FONT_REGULAR / PDF_FONT_BOLD at a TTF (Noto Sans works well) to fix that.
 */
function resolveFontPaths() {
  const { fontRegular, fontBold } = env.pdf;
  if (!fontRegular || !fontBold) return undefined;

  if (!fs.existsSync(fontRegular) || !fs.existsSync(fontBold)) {
    // eslint-disable-next-line no-console
    console.warn('PDF_FONT_REGULAR / PDF_FONT_BOLD not found on disk - falling back to Helvetica');
    return undefined;
  }
  return { regular: fontRegular, bold: fontBold };
}

async function getInvoicePdf(userId, invoiceId) {
  const invoice = await invoicesRepo.findByIdForUser(invoiceId, userId);
  if (!invoice) throw AppError.notFound('Invoice not found');

  const user = await usersRepo.findById(userId);

  // Checked against the user's *current* plan, not a snapshot: upgrading to Pro
  // cleans up old invoices too, and a downgrade brings the watermark back.
  const watermark = user.plan_type !== 'pro';

  const buffer = await renderInvoicePdf({
    invoice,
    business: {
      name: user.business_name,
      email: user.email,
      phone: user.contact_phone,
      address: user.contact_address,
    },
    watermark,
    fonts: resolveFontPaths(),
  });

  return { buffer, filename: `${invoice.invoice_number}.pdf` };
}

module.exports = { getInvoicePdf };

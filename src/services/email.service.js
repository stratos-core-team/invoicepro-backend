const fs = require('fs');
const path = require('path');
const env = require('../config/env');

const PREVIEW_DIR = path.join(process.cwd(), 'tmp', 'email-previews');

/** Dev convenience: saves attachments to disk so they can actually be opened while no provider is configured. */
function savePreviewAttachments(attachments) {
  try {
    fs.mkdirSync(PREVIEW_DIR, { recursive: true });
    return attachments.map((a) => {
      const filePath = path.join(PREVIEW_DIR, `${Date.now()}-${a.filename}`);
      fs.writeFileSync(filePath, a.content);
      return filePath;
    });
  } catch (err) {
    // eslint-disable-next-line no-console
    console.warn('Could not save email preview attachment:', err.message);
    return [];
  }
}

/**
 * Sends one email.
 *
 * @param {object} message
 * @param {string} message.to
 * @param {string} message.subject
 * @param {string} message.html
 * @param {string} [message.replyTo]   e.g. the freelancer's own email, so client replies skip InvoicePro NG
 * @param {string} [message.fromName]  display name shown to the recipient, e.g. the freelancer's business name
 * @param {{filename: string, content: Buffer}[]} [message.attachments]
 *
 * Development (no RESEND_API_KEY): logs the email and saves any attachments
 * under ./tmp/email-previews instead of sending, so flows can be built and
 * tested without a provider. Production without a key throws — silently
 * dropping an email would be worse than a visible failure.
 */
async function send({ to, subject, html, replyTo, fromName, attachments = [] }) {
  if (!env.email.resendApiKey) {
    if (env.isProd) {
      throw new Error('Email provider is not configured (RESEND_API_KEY is missing)');
    }
    const savedPaths = attachments.length ? savePreviewAttachments(attachments) : [];
    // eslint-disable-next-line no-console
    console.log(
      `\n[email:stub] To: ${to}\nSubject: ${subject}\nReply-To: ${replyTo || '-'}\n${html}\n` +
        (savedPaths.length ? `Attachments saved to:\n  ${savedPaths.join('\n  ')}\n` : '')
    );
    return { id: 'stub', delivered: false };
  }

  const payload = { from: formatFrom(fromName), to, subject, html };
  if (replyTo) payload.reply_to = replyTo;
  if (attachments.length > 0) {
    payload.attachments = attachments.map((a) => ({
      filename: a.filename,
      content: a.content.toString('base64'), // Resend's REST API expects base64 strings
    }));
  }

  const res = await fetch('https://api.resend.com/emails', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${env.email.resendApiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });

  if (!res.ok) {
    const body = await res.text();
    throw new Error(`Resend API error (${res.status}): ${body}`);
  }
  const data = await res.json();
  return { id: data.id, delivered: true };
}

/** "Grace Hopper <no-reply@invoicepro.ng>" — reuses the one verified sender address with a per-email display name. */
function formatFrom(fromName) {
  if (!fromName) return env.email.from;
  const address = /<(.+)>/.exec(env.email.from)?.[1] || env.email.from;
  return `${fromName} <${address}>`;
}

function sendConfirmationEmail(user) {
  return send({
    to: user.email,
    subject: 'Welcome to InvoicePro NG — your account is ready',
    html: `<p>Hi ${user.full_name},</p><p>Your InvoicePro NG account has been created successfully. Log in to set up your business profile and send your first invoice.</p>`,
  });
}

module.exports = { send, sendConfirmationEmail };

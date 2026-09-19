const env = require('../config/env');
const logger = require('./logger');

/**
 * Stub mailer. Swap the body of send() for a real Resend/Nodemailer call.
 * Kept as a single choke point so retry-queue logic (per PRD risk mitigation)
 * only needs to be added here later.
 */
async function send({ to, subject, html }) {
  if (!env.email.resendApiKey) {
    logger.warn(`[mailer] RESEND_API_KEY not set — logging email instead of sending. To: ${to}, Subject: ${subject}`);
    return { simulated: true };
  }

  // Example real integration (Resend):
  // const res = await fetch('https://api.resend.com/emails', {
  //   method: 'POST',
  //   headers: {
  //     Authorization: `Bearer ${env.email.resendApiKey}`,
  //     'Content-Type': 'application/json',
  //   },
  //   body: JSON.stringify({ from: env.email.from, to, subject, html }),
  // });
  // if (!res.ok) throw new Error(`Email send failed: ${res.status}`);
  // return res.json();

  return { simulated: true };
}

async function sendWelcomeEmail(toEmail, fullName) {
  return send({
    to: toEmail,
    subject: 'Welcome to InvoicePro NG 🎉',
    html: `<p>Hi ${fullName},</p><p>Your InvoicePro NG account has been created successfully. Let's get your first invoice out the door.</p>`,
  });
}

module.exports = { send, sendWelcomeEmail };

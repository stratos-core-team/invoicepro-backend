const env = require('../config/env');

/**
 * Minimal email sender. While RESEND_API_KEY is unset, this just logs the
 * email to the console so you can develop auth flows without an email
 * provider. Once you add a key, fill in the fetch() call below — callers
 * (auth.service.js etc.) never need to change.
 */
async function send({ to, subject, html }) {
  if (!env.email.resendApiKey) {
    // eslint-disable-next-line no-console
    console.log(`\n[email:stub] To: ${to}\nSubject: ${subject}\n${html}\n`);
    return { id: 'stub', delivered: false };
  }

  const res = await fetch('https://api.resend.com/emails', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${env.email.resendApiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ from: env.email.from, to, subject, html }),
  });

  if (!res.ok) {
    const body = await res.text();
    throw new Error(`Resend API error (${res.status}): ${body}`);
  }
  return res.json();
}

function sendConfirmationEmail(user) {
  return send({
    to: user.email,
    subject: 'Welcome to InvoicePro NG — your account is ready',
    html: `<p>Hi ${user.full_name},</p><p>Your InvoicePro NG account has been created successfully. Log in to set up your business profile and send your first invoice.</p>`,
  });
}

module.exports = { send, sendConfirmationEmail };

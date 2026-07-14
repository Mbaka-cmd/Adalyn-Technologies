# Adalyn Payments

Tracks client quotations, projects, and M-Pesa (Daraja) deposit + balance payments,
with a client-facing status page and an internal dashboard.

## What this does

1. You create a **Quotation** in the admin (client, project title, total amount).
2. A **Project** is auto-linked, starting in "Awaiting Deposit" stage.
3. Client is sent a link like `https://yourdomain.com/payments/status/ADQ-XXXXXXXX/`
   (via WhatsApp/email — this could later be wired into your Proposal Generator).
4. Client opens the link, sees the amount due, enters their phone number, taps
   **Pay with M-Pesa** -> an STK Push prompt appears on their phone.
5. On successful payment, Daraja calls your `MPESA_CALLBACK_URL`, which marks the
   payment complete and automatically advances the project to "In Progress."
6. Once you mark the project as "Awaiting Balance Payment" (in admin, when you're
   ready to deliver), the client can pay the balance the same way.
7. You see everything — all quotations, who's paid what, project stage — at
   `/payments/dashboard/` (staff login required).

## Setup on Windows (PowerShell)

```powershell
cd "C:\Users\Superior Creature\Desktop"
# unzip the project here, then:
cd adalyn_payments

python -m venv venv
.\venv\Scripts\Activate.ps1
pip install -r requirements.txt

python manage.py migrate
python manage.py createsuperuser
python manage.py runserver
```

Visit:
- `http://127.0.0.1:8000/admin/` — log in, create Clients and Quotations
- `http://127.0.0.1:8000/payments/dashboard/` — your payment tracking dashboard
- `http://127.0.0.1:8000/payments/status/<reference>/` — the client-facing link
  (reference is auto-generated per quotation, e.g. `ADQ-A1B2C3D4`)

## M-Pesa (Daraja) credentials

1. Sign up at https://developer.safaricom.co.ke
2. Create an app to get sandbox `Consumer Key` and `Consumer Secret`
3. Get the sandbox `Passkey` from the Daraja portal (Lipa Na M-Pesa Online)
4. Sandbox shortcode is `174379` (already set as default)

Set these as environment variables before running the server (PowerShell):

```powershell
$env:MPESA_CONSUMER_KEY = "your_key"
$env:MPESA_CONSUMER_SECRET = "your_secret"
$env:MPESA_PASSKEY = "your_passkey"
$env:MPESA_CALLBACK_URL = "https://your-public-url/payments/mpesa/callback/"
python manage.py runserver
```

**Important:** Daraja cannot call back to `localhost`. For local testing, use
[ngrok](https://ngrok.com) to expose your local server:

```powershell
ngrok http 8000
```

Then use the ngrok HTTPS URL + `/payments/mpesa/callback/` as your `MPESA_CALLBACK_URL`.

When you're ready to go live, switch `MPESA_ENV=production`, get production
credentials from Safaricom (requires a registered Paybill/Till), and deploy
this behind a real HTTPS domain (e.g. on Render, same as your other projects).

## Next steps you may want

- Wire your Proposal Generator to create a `Quotation` automatically when a
  client accepts a proposal, and auto-send them the status link on WhatsApp.
- Add email notifications when a payment completes.
- Add a "Mark project delivered" button in the client status page workflow
  once balance is paid, instead of manually flipping it in admin.

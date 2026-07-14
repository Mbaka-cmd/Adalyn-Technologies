"""
Daraja (Safaricom M-Pesa) STK Push integration.

Setup required in your .env / settings:
    MPESA_ENV = "sandbox" or "production"
    MPESA_CONSUMER_KEY
    MPESA_CONSUMER_SECRET
    MPESA_SHORTCODE          (Paybill or Till number)
    MPESA_PASSKEY            (Lipa Na M-Pesa Online passkey, from Daraja portal)
    MPESA_CALLBACK_URL       (Publicly accessible HTTPS URL for payment confirmation,
                               e.g. https://yourdomain.com/payments/mpesa/callback/)

Get sandbox credentials at https://developer.safaricom.co.ke
"""
import base64
import datetime
import requests
from django.conf import settings

SANDBOX_BASE_URL = "https://sandbox.safaricom.co.ke"
PRODUCTION_BASE_URL = "https://api.safaricom.co.ke"


def get_base_url():
    return PRODUCTION_BASE_URL if getattr(settings, "MPESA_ENV", "sandbox") == "production" else SANDBOX_BASE_URL


def get_access_token():
    """Fetch an OAuth access token from Daraja."""
    url = f"{get_base_url()}/oauth/v1/generate?grant_type=client_credentials"
    response = requests.get(
        url,
        auth=(settings.MPESA_CONSUMER_KEY, settings.MPESA_CONSUMER_SECRET),
        timeout=30,
    )
    response.raise_for_status()
    return response.json()["access_token"]


def generate_password_and_timestamp():
    timestamp = datetime.datetime.now().strftime("%Y%m%d%H%M%S")
    data_to_encode = f"{settings.MPESA_SHORTCODE}{settings.MPESA_PASSKEY}{timestamp}"
    password = base64.b64encode(data_to_encode.encode()).decode()
    return password, timestamp


def normalize_phone(phone: str) -> str:
    """Convert local formats (07..., +2547...) to 2547XXXXXXXX."""
    phone = phone.strip().replace(" ", "").replace("+", "")
    if phone.startswith("0"):
        phone = "254" + phone[1:]
    return phone


def initiate_stk_push(phone_number: str, amount: float, account_reference: str, transaction_desc: str):
    """
    Triggers an STK Push prompt on the client's phone.
    Returns the parsed JSON response from Daraja, which includes
    CheckoutRequestID and MerchantRequestID to track the transaction.
    """
    access_token = get_access_token()
    password, timestamp = generate_password_and_timestamp()
    phone_number = normalize_phone(phone_number)

    url = f"{get_base_url()}/mpesa/stkpush/v1/processrequest"
    headers = {"Authorization": f"Bearer {access_token}"}
    payload = {
        "BusinessShortCode": settings.MPESA_SHORTCODE,
        "Password": password,
        "Timestamp": timestamp,
        "TransactionType": "CustomerPayBillOnline",
        "Amount": int(round(amount)),
        "PartyA": phone_number,
        "PartyB": settings.MPESA_SHORTCODE,
        "PhoneNumber": phone_number,
        "CallBackURL": settings.MPESA_CALLBACK_URL,
        "AccountReference": account_reference[:20],
        "TransactionDesc": transaction_desc[:100],
    }

    response = requests.post(url, json=payload, headers=headers, timeout=30)
    response.raise_for_status()
    return response.json()

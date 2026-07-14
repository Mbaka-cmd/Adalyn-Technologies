import json
import logging

from django.contrib.admin.views.decorators import staff_member_required
from django.http import JsonResponse
from django.shortcuts import render, get_object_or_404
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_POST

from .models import Quotation, Payment
from .daraja import initiate_stk_push

logger = logging.getLogger(__name__)


@staff_member_required
def dashboard(request):
    """Internal dashboard: see all quotations, projects, and payment history."""
    quotations = Quotation.objects.select_related("client").prefetch_related("payments").order_by("-created_at")
    return render(request, "payments/dashboard.html", {"quotations": quotations})


def client_status(request, reference):
    """
    Client-facing page (no login required, reached via the quotation reference link
    sent to them on WhatsApp/email). Shows project status and lets them pay the
    next amount due (deposit or balance).
    """
    quotation = get_object_or_404(Quotation, reference=reference)
    project = getattr(quotation, "project", None)
    payments = quotation.payments.order_by("-created_at")

    amount_paid = sum(p.amount for p in payments if p.status == "completed")
    deposit_paid = payments.filter(payment_type="deposit", status="completed").exists()
    balance_paid = payments.filter(payment_type="balance", status="completed").exists()

    if not deposit_paid:
        next_payment_type = "deposit"
        next_amount = quotation.deposit_amount
    elif not balance_paid:
        next_payment_type = "balance"
        next_amount = quotation.balance_amount
    else:
        next_payment_type = None
        next_amount = 0

    context = {
        "quotation": quotation,
        "project": project,
        "payments": payments,
        "amount_paid": amount_paid,
        "next_payment_type": next_payment_type,
        "next_amount": next_amount,
    }
    return render(request, "payments/client_status.html", context)


@require_POST
def trigger_payment(request, reference):
    """
    Client clicks "Pay Deposit" or "Pay Balance" -> we trigger an STK Push.
    Expects POST with: phone_number, payment_type ("deposit" or "balance")
    """
    quotation = get_object_or_404(Quotation, reference=reference)
    phone_number = request.POST.get("phone_number", "").strip()
    payment_type = request.POST.get("payment_type")

    if payment_type == "deposit":
        amount = quotation.deposit_amount
        desc = f"Deposit for {quotation.title}"
    elif payment_type == "balance":
        amount = quotation.balance_amount
        desc = f"Balance for {quotation.title}"
    else:
        return JsonResponse({"error": "Invalid payment type"}, status=400)

    if not phone_number:
        return JsonResponse({"error": "Phone number is required"}, status=400)

    payment = Payment.objects.create(
        quotation=quotation,
        payment_type=payment_type,
        amount=amount,
        phone_number=phone_number,
        status="pending",
    )

    try:
        result = initiate_stk_push(
            phone_number=phone_number,
            amount=amount,
            account_reference=quotation.reference,
            transaction_desc=desc,
        )
    except Exception as exc:
        logger.exception("STK Push failed for quotation %s", quotation.reference)
        payment.status = "failed"
        payment.result_desc = str(exc)
        payment.save()
        return JsonResponse({"error": "Could not initiate payment. Please try again."}, status=502)

    payment.merchant_request_id = result.get("MerchantRequestID", "")
    payment.checkout_request_id = result.get("CheckoutRequestID", "")
    payment.status = "processing"
    payment.save()

    return JsonResponse({
        "message": "Check your phone and enter your M-Pesa PIN to complete payment.",
        "payment_id": str(payment.id),
    })


@csrf_exempt
@require_POST
def mpesa_callback(request):
    """
    Daraja calls this URL after the STK Push flow completes (success or fail).
    Must be registered as MPESA_CALLBACK_URL and be publicly reachable over HTTPS.
    """
    try:
        data = json.loads(request.body)
    except (json.JSONDecodeError, UnicodeDecodeError):
        logger.error("Invalid M-Pesa callback payload")
        return JsonResponse({"ResultCode": 1, "ResultDesc": "Invalid payload"})

    stk_callback = data.get("Body", {}).get("stkCallback", {})
    checkout_request_id = stk_callback.get("CheckoutRequestID")
    result_code = stk_callback.get("ResultCode")
    result_desc = stk_callback.get("ResultDesc", "")

    try:
        payment = Payment.objects.get(checkout_request_id=checkout_request_id)
    except Payment.DoesNotExist:
        logger.error("No matching payment for CheckoutRequestID %s", checkout_request_id)
        return JsonResponse({"ResultCode": 0, "ResultDesc": "Accepted"})

    payment.result_code = str(result_code)
    payment.result_desc = result_desc

    if result_code == 0:
        # Successful payment - extract the M-Pesa receipt number from CallbackMetadata
        receipt = ""
        for item in stk_callback.get("CallbackMetadata", {}).get("Item", []):
            if item.get("Name") == "MpesaReceiptNumber":
                receipt = item.get("Value", "")
        payment.mark_completed(mpesa_receipt=receipt)
    else:
        payment.status = "failed"
        payment.save()

    return JsonResponse({"ResultCode": 0, "ResultDesc": "Accepted"})

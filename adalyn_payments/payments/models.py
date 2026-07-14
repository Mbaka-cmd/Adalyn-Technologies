import uuid
from django.db import models
from django.utils import timezone


class Client(models.Model):
    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    name = models.CharField(max_length=150)
    email = models.EmailField(blank=True)
    phone = models.CharField(max_length=20, help_text="Format: 2547XXXXXXXX")
    organization = models.CharField(max_length=150, blank=True)
    created_at = models.DateTimeField(auto_now_add=True)

    def __str__(self):
        return f"{self.name} ({self.phone})"


class Quotation(models.Model):
    STATUS_CHOICES = [
        ("draft", "Draft"),
        ("sent", "Sent to Client"),
        ("accepted", "Accepted"),
        ("declined", "Declined"),
        ("expired", "Expired"),
    ]

    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    reference = models.CharField(max_length=20, unique=True, editable=False)
    client = models.ForeignKey(Client, on_delete=models.CASCADE, related_name="quotations")
    title = models.CharField(max_length=200, help_text="e.g. School ERP System - Chuka Girls")
    description = models.TextField(blank=True)
    total_amount = models.DecimalField(max_digits=12, decimal_places=2, help_text="Total project cost in KES")
    deposit_percentage = models.PositiveIntegerField(default=50, help_text="Percentage required as deposit before work starts")
    status = models.CharField(max_length=20, choices=STATUS_CHOICES, default="draft")
    created_at = models.DateTimeField(auto_now_add=True)
    valid_until = models.DateField(null=True, blank=True)

    def save(self, *args, **kwargs):
        if not self.reference:
            self.reference = f"ADQ-{uuid.uuid4().hex[:8].upper()}"
        super().save(*args, **kwargs)

    @property
    def deposit_amount(self):
        return round(self.total_amount * self.deposit_percentage / 100, 2)

    @property
    def balance_amount(self):
        return round(self.total_amount - self.deposit_amount, 2)

    def __str__(self):
        return f"{self.reference} - {self.title}"


class Project(models.Model):
    STAGE_CHOICES = [
        ("awaiting_deposit", "Awaiting Deposit"),
        ("in_progress", "In Progress"),
        ("review", "Client Review"),
        ("awaiting_balance", "Awaiting Balance Payment"),
        ("delivered", "Delivered"),
        ("closed", "Closed"),
    ]

    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    quotation = models.OneToOneField(Quotation, on_delete=models.CASCADE, related_name="project")
    stage = models.CharField(max_length=25, choices=STAGE_CHOICES, default="awaiting_deposit")
    started_at = models.DateTimeField(null=True, blank=True)
    delivered_at = models.DateTimeField(null=True, blank=True)
    notes = models.TextField(blank=True, help_text="Internal notes about project progress")

    @property
    def client(self):
        return self.quotation.client

    def mark_started(self):
        self.stage = "in_progress"
        self.started_at = timezone.now()
        self.save()

    def mark_delivered(self):
        self.stage = "delivered"
        self.delivered_at = timezone.now()
        self.save()

    def __str__(self):
        return f"Project: {self.quotation.title} [{self.get_stage_display()}]"


class Payment(models.Model):
    PAYMENT_TYPE_CHOICES = [
        ("deposit", "Deposit (50%)"),
        ("balance", "Balance Payment"),
        ("full", "Full Payment"),
        ("other", "Other"),
    ]
    STATUS_CHOICES = [
        ("pending", "Pending"),
        ("processing", "Processing (STK Push Sent)"),
        ("completed", "Completed"),
        ("failed", "Failed"),
        ("cancelled", "Cancelled"),
    ]

    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    quotation = models.ForeignKey(Quotation, on_delete=models.CASCADE, related_name="payments")
    payment_type = models.CharField(max_length=10, choices=PAYMENT_TYPE_CHOICES)
    amount = models.DecimalField(max_digits=12, decimal_places=2)
    phone_number = models.CharField(max_length=20, help_text="Phone number STK push was sent to")
    status = models.CharField(max_length=15, choices=STATUS_CHOICES, default="pending")

    # M-Pesa / Daraja specific fields
    merchant_request_id = models.CharField(max_length=100, blank=True)
    checkout_request_id = models.CharField(max_length=100, blank=True)
    mpesa_receipt_number = models.CharField(max_length=50, blank=True)
    result_code = models.CharField(max_length=10, blank=True)
    result_desc = models.CharField(max_length=255, blank=True)

    created_at = models.DateTimeField(auto_now_add=True)
    completed_at = models.DateTimeField(null=True, blank=True)

    def mark_completed(self, mpesa_receipt=""):
        self.status = "completed"
        self.mpesa_receipt_number = mpesa_receipt
        self.completed_at = timezone.now()
        self.save()

        # Advance the project stage automatically
        project = getattr(self.quotation, "project", None)
        if project:
            if self.payment_type == "deposit" and project.stage == "awaiting_deposit":
                project.mark_started()
            elif self.payment_type == "balance" and project.stage in ("awaiting_balance", "review"):
                project.mark_delivered()

    def __str__(self):
        return f"{self.get_payment_type_display()} - KES {self.amount} [{self.status}]"

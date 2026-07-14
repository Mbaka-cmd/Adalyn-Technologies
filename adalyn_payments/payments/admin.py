from django.contrib import admin
from .models import Client, Quotation, Project, Payment


@admin.register(Client)
class ClientAdmin(admin.ModelAdmin):
    list_display = ("name", "phone", "email", "organization", "created_at")
    search_fields = ("name", "phone", "email", "organization")


class PaymentInline(admin.TabularInline):
    model = Payment
    extra = 0
    readonly_fields = ("id", "created_at", "completed_at", "mpesa_receipt_number", "checkout_request_id")
    fields = ("payment_type", "amount", "status", "mpesa_receipt_number", "created_at", "completed_at")


class ProjectInline(admin.StackedInline):
    model = Project
    extra = 0
    readonly_fields = ("started_at", "delivered_at")


@admin.register(Quotation)
class QuotationAdmin(admin.ModelAdmin):
    list_display = ("reference", "title", "client", "total_amount", "deposit_amount_display", "status", "created_at")
    list_filter = ("status",)
    search_fields = ("reference", "title", "client__name", "client__phone")
    readonly_fields = ("reference", "deposit_amount_display", "balance_amount_display")
    inlines = [ProjectInline, PaymentInline]

    def deposit_amount_display(self, obj):
        return f"KES {obj.deposit_amount:,.2f}"
    deposit_amount_display.short_description = "Deposit Due"

    def balance_amount_display(self, obj):
        return f"KES {obj.balance_amount:,.2f}"
    balance_amount_display.short_description = "Balance Due"


@admin.register(Project)
class ProjectAdmin(admin.ModelAdmin):
    list_display = ("quotation", "client_name", "stage", "started_at", "delivered_at")
    list_filter = ("stage",)

    def client_name(self, obj):
        return obj.client.name
    client_name.short_description = "Client"


@admin.register(Payment)
class PaymentAdmin(admin.ModelAdmin):
    list_display = ("quotation", "payment_type", "amount", "status", "phone_number", "mpesa_receipt_number", "created_at")
    list_filter = ("status", "payment_type")
    search_fields = ("quotation__reference", "phone_number", "mpesa_receipt_number", "checkout_request_id")
    readonly_fields = (
        "id", "merchant_request_id", "checkout_request_id",
        "mpesa_receipt_number", "result_code", "result_desc",
        "created_at", "completed_at",
    )

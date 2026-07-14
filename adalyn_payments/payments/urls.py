from django.urls import path
from . import views

app_name = "payments"

urlpatterns = [
    path("dashboard/", views.dashboard, name="dashboard"),
    path("status/<str:reference>/", views.client_status, name="client_status"),
    path("status/<str:reference>/pay/", views.trigger_payment, name="trigger_payment"),
    path("mpesa/callback/", views.mpesa_callback, name="mpesa_callback"),
]

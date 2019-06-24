from django.urls import path

from . import views

urlpatterns = [
    # Example: /
    path('', views.index, name='index'),
    # Example /players/5
    path('players/<int:player_id>/', views.details, name='details')
]
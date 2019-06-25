from django.urls import path

from . import views

app_name = 'fpl'
urlpatterns = [
    # Example: /
    path('', views.IndexView.as_view(), name='index'),
    # Example /players/5
    path('players/<int:pk>/', views.DetailView.as_view(), name='detail')
]
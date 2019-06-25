from django.views import generic

from .models import Player


class IndexView(generic.ListView):
    template_name = 'fpl/index.html'
    context_object_name = 'highest_scoring_players'

    def get_queryset(self):
        """Return the 5 highest scoring players"""
        return Player.objects.order_by('-goals')[:5]


class DetailView(generic.DetailView):
    model = Player
    template_name = 'fpl/player.html'
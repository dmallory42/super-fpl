from django.shortcuts import render, get_object_or_404
from django.http import HttpResponse, Http404

from .models import Player


def index(request):
    highest_scoring_players = Player.objects.order_by('-goals')[:5]
    context = {
        'highest_scoring_players': highest_scoring_players
    }
    return render(request, 'fpl/index.html', context)

def details(request, player_id):
    player = get_object_or_404(Player, pk=player_id)
    return render(request, 'fpl/player.html', {'player': player})
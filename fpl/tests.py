from django.test import TestCase
from django.utils import timezone

from .models import Player

class PlayerModelTests(TestCase):
    def test_calculate_gpg(self):
        """calculate_gpg() cannot return a negative result"""
        player = Player(games=10, goals=5)
        self.assertIs(player.calculate_gpg(), 0.50)

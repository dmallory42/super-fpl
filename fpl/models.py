from django.db import models


# Create your models here.
class Player(models.Model):
    def __str__(self):
        return 'Player: ' + self.player_name

    def calculate_gpg(self):
        return float("{0:.2f}".format(self.goals / self.games))

    assists = models.IntegerField()
    games = models.IntegerField()
    goals = models.IntegerField()
    id = models.IntegerField(primary_key=True)
    key_passes = models.IntegerField()
    npg = models.IntegerField()
    npxG = models.FloatField()
    player_name = models.TextField()
    position = models.TextField()
    red_cards = models.IntegerField()
    shots = models.IntegerField()
    team_title = models.TextField()
    time = models.IntegerField()
    xA = models.FloatField()
    xG = models.FloatField()
    xGBuildup = models.FloatField()
    xGChain = models.FloatField()
    yellow_cards = models.FloatField()
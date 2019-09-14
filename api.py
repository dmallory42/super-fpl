from utils import Utils
from db import Db
from logging import Logger

import urllib.request
import json
import decimal

class Api:
    # Constants: the various routes of interest
    API_URL = 'https://fantasy.premierleague.com/api'
    ALL_DATA = '/bootstrap-static/'
    FIXTURES = '/fixtures/'

    def __init__(self, logger : Logger):
        self.logger : Logger = logger
        self.db = Db(logger)
        self.utils = Utils()

    def get_players(
        self, 
        min_price: float = None, 
        max_price: float = None, 
        min_minutes_played: int = None, 
        positions: list = None,
        max_ownership: float = None
    ):
        players = self.db.get_players_from_db(
            min_price, 
            max_price, 
            min_minutes_played, 
            positions, 
            max_ownership
        )
        
        if players is not False:
            return players
        else:
            with urllib.request.urlopen(self.API_URL + self.ALL_DATA) as url:
                data = json.loads(url.read().decode())
                if 'elements' in data:
                    # Insert our players to our DB:
                    for player in data['elements']:
                        # Format our players to add additional metrics we may be interested in:
                        player = self.utils.format_player(player)

                    self.db.insert_players_to_db(data['elements'])

                    # Get the players from the DB (means we can apply any filters needed):
                    players = self.db.get_players_from_db(min_price, max_price, min_minutes_played, positions, max_ownership)
                else:
                    return False
    
    def get_teams(self):
        teams = self.db.get_teams_from_db()

        if teams is not False:
            return teams
        else:
            with urllib.request.urlopen(self.API_URL + self.ALL_DATA) as url:
                data = json.loads(url.read().decode())
                if 'teams' in data:
                    self.db.insert_teams_to_db(data['teams'])

                    # Get the teams from the DB (means we can apply any filters needed):
                    return self.db.get_teams_from_db()
                else:
                    return False

    def get_fixtures(self):
        fixtures = self.db.get_fixtures_from_db()

        if fixtures is not False:
            return fixtures
        else:
            with urllib.request.urlopen(self.API_URL + self.FIXTURES) as url:
                data = json.loads(url.read().decode())
                self.db.insert_fixtures_to_db(data)

                # Get the fixtures from the DB (means we can apply any filters we want):
                return self.db.get_fixtures_from_db()

    def get_value_range(self):
        min_and_max = self.db.get_min_and_max_value()

        value_range = []

        i = min_and_max['min_value']
        while i <= min_and_max['max_value']:
            value_range.append(round(i,1))
            i += 0.1

        return value_range
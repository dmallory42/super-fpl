from utils import Utils
from db import Db
from logging import Logger

import urllib.request
import json


class Api:
    # Constants: the various routes of interest
    API_URL = 'https://fantasy.premierleague.com/api'
    ALL_DATA = '/bootstrap-static/'

    def __init__(self, logger : Logger):
        self.logger : Logger = logger
        self.db = Db(logger)
        self.utils = Utils()

    def get_players(self):
        players = self.db.get_players_from_db()
        
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

                    # Return the data to the frontend:
                    return data['elements']
                else:
                    return False

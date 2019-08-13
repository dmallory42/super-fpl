from utils import Utils
from pymongo import MongoClient
from datetime import date

import urllib.request
import json


class Api:
    # Constants: the various routes of interest
    API_URL = 'https://fantasy.premierleague.com/api'
    ALL_DATA = '/bootstrap-static'

    utils = Utils()
    client = MongoClient()
    db = client.fpl

    def get_players(self):
        # players = self.get_players_from_db()
        players = False

        if players is not False:
            return players
        else:
            with urllib.request.urlopen(self.API_URL + self.ALL_DATA) as url:
                data = json.loads(url.read().decode())
                if 'elements' in data:
                    # Insert our players to our DB:
                    self.insert_players_to_db(data['elements'])

                    # Return the data to the frontend:
                    return data['elements']
                else:
                    return False
    
    def get_players_from_db(self):
        today = date.today()

        cursor = self.db.players.find({"date_generated": today.strftime("%d%m%Y")})    
        players = []

        for player in cursor:
            players.append(player)
    
        return players

    def insert_players_to_db(self, players):
        for player in players:
            # Format our players to add additional metrics we may be interested in:
            player = self.utils.format_player(player)

            # Generate our UID (used to track changes)
            player['_id'] = self.generate_uid(player['id'])

            self.db.players.save(player)

    def generate_uid(self, player_id):
        # use todays date in our unique ID:
        today = date.today()

        return str(player_id) + '_' + today.strftime("%d%m%Y")

    def check_record_exists(self, player_id):
        uid = self.generate_uid(player_id)
        return self.db.players.find_one({"_id": uid}, {"_id": 1}).limit(1)


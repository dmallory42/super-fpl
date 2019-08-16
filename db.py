from pymongo import MongoClient
from datetime import date
from logging import Logger

class Db:
    def __init__(self, logger : Logger):
        self.logger : Logger = logger
        self.client : MongoClient = self.connect()
        self.db = self.client.fpl

    def connect(self):
        self.logger.debug('testing the logging in the DB')
        try:
            client = MongoClient()
        except Exception as e:
            self.logger.error(e)
        
        return client
            
    def get_players_from_db(self):
        today = date.today()

        cursor = self.db.players.find({"date_generated": today.strftime("%d%m%Y")})    
        players = []

        for player in cursor:
            players.append(player)
    
        if len(players) == 0:
            return False

        return players

    def insert_players_to_db(self, players):
        for player in players:
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
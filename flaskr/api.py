from flask_redis import FlaskRedis

import urllib.request
import json


class Api:
    redis = None    # type: FlaskRedis

    # Constants: the various routes of interest
    API_URL = 'https://fantasy.premierleague.com/api'
    ALL_DATA = '/bootstrap-static'

    def __init__(self, redis: FlaskRedis):
        self.redis = redis

    def get_players(self):
        players = self.get_key('players.2018')

        if players is not None:
            return players
        else:
            with urllib.request.urlopen(self.API_URL + self.ALL_DATA) as url:
                data = json.loads(url.read().decode())
                if 'elements' in data:
                    self.set_key('players.2018', data['elements'])
                    return data['elements']
                else:
                    return False

    def get_key(self, key):
        val = self.redis.get(key)
        try:
            parsed = json.loads(val)
            return parsed
        except ValueError:
            # If this is not a JSON object, then just return as is:
            return val

    def set_key(self, key, val):
        if isinstance(val, dict):
            self.redis.set(key, json.dumps(val))
        else:
            self.redis.set(key, val)

    def del_key(self, key):
        self.redis.delete(key)

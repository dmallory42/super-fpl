import os

from unidecode import unidecode
from flask import (
    Flask, flash, g, redirect, render_template, request, session, url_for, jsonify, current_app
)
from flask.logging import create_logger

from api import Api
from datetime import date

import logging

def create_app(test_config=None):
    # create and configure the app
    app = Flask(__name__, instance_relative_config=True)
    app.config.from_mapping(
        SECRET_KEY='dev',
        DATABASE=os.path.join(app.instance_path, 'flaskr.sqlite'),
        REDIS_HOST='localhost',
        REDIS_PORT=6379,
        REDIS_DB=0
    )

    if test_config is None:
        # load the instance config, if it exists, when not testing
        app.config.from_pyfile('config.py', silent=True)
    else:
        # load the test config if passed in
        app.config.from_mapping(test_config)

    # Configure logging:
    logging.basicConfig(
        filename='logs/' + date.today().strftime('%d-%m-%Y') + '.log', 
        level=logging.WARNING,
        format='%(asctime)s %(levelname)s %(name)s %(threadName)s : %(message)s'
    )

    # ensure the instance folder exists
    try:
        os.makedirs(app.instance_path)
    except OSError:
        pass

    return app

app = create_app()  # Flask
logger = create_logger(app)
api = Api(logger)

@app.route('/', methods=['GET'])
def home():
    players = api.get_players()
    players = sorted(players, key=lambda k: unidecode(k['name']))

    valid_players = []
    
    for player in players:
        if player['minutes'] > 0:
            valid_players.append(player)

    return render_template('fpl/home.html', title='Super FPL - Player Comparison Tool', players=valid_players)


@app.route('/player_stats', methods=['GET'])
def get_player_stats():
    players = api.get_players()
    players = sorted(players, key=lambda k: unidecode(k['name']))
    
    fixtures = api.get_fixtures()
    team_fixtures = {}

    for fixture in fixtures:
        home_team_id = fixture['team_h']
        away_team_id = fixture['team_a']

        if home_team_id not in team_fixtures:
            team_fixtures[home_team_id] = []

        if away_team_id not in team_fixtures:
            team_fixtures[away_team_id] = []
            
        team_fixtures[home_team_id].append(fixture)
        team_fixtures[away_team_id].append(fixture)

    # get our teams and then format them:
    teams = api.get_teams()
    teams_formatted = {}
    teams_by_id = {}
    for team in teams:
        code = team['code']
        team['fixtures'] = team_fixtures[team['id']]
        teams_formatted[code] = team
        teams_by_id[team['id']] = team

    for player in players:
        player['team'] = teams_formatted[player['team_code']]

    return render_template('fpl/player_stats.html', title='Super FPL - Player Stats Database', players=players, teams=teams_by_id)


@app.route('/ajax/players', methods=['GET'])
def get_ajax_players():
    players = api.get_players()
    return jsonify(players)


@app.route('/ajax/player_stats', methods=['GET'])
def get_ajax_player_stats():
    players = api.get_players()
    players = sorted(players, key=lambda k: unidecode(k['name']))

    fixtures = api.get_fixtures()
    team_fixtures = {}

    for fixture in fixtures:
        home_team_id = fixture['team_h']
        away_team_id = fixture['team_a']

        if home_team_id not in team_fixtures:
            team_fixtures[home_team_id] = []

        if away_team_id not in team_fixtures:
            team_fixtures[away_team_id] = []

        team_fixtures[home_team_id].append(fixture)
        team_fixtures[away_team_id].append(fixture)

    # get our teams and then format them:
    teams = api.get_teams()
    teams_formatted = {}
    for team in teams:
        code = team['code']
        team['fixtures'] = team_fixtures[team['id']]
        teams_formatted[code] = team

    for player in players:
        player['team'] = teams_formatted[player['team_code']]

    # Return in a format readable by DataTables
    return jsonify({"data": players})


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8000)

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
    logger.error('test')
    logger.info('test2')
    players = api.get_players()
    players = sorted(players, key=lambda k: unidecode(k['name']))

    valid_players = []
    
    for player in players:
        if player['minutes'] > 0:
            valid_players.append(player)

    return render_template('fpl/home.html', title='Super FPL - Player Comparison Tool', players=valid_players)


@app.route('/players', methods=['GET'])
def get_players():
    players = api.get_players()
    return jsonify(players)

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8000)
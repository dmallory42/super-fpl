from flask import (
    Flask, flash, g, redirect, render_template, request, session, url_for, jsonify, current_app
)

from .api import Api
from . import create_app

app = create_app()
api = Api(app.extensions['redis'])


@app.route('/', methods=['GET'])
def home():
    return render_template('fpl/home.html')


@app.route('/players', methods=['GET'])
def get_players():
    players = api.get_players()
    return jsonify(players)

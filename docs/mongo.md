# Mongo Setup Documentation

MongoDB is used to keep track of all the player records. Records are stored in a Database called 'fpl', under a collection called 'players'. Each player has an _id param which is used as their unique identifier within mongo, this is generated from the players 'id' within the FPL API, plus todays datestamp in ddmmYYYY format. For example, Mustafi would be '1_09082019' for the 9th August.

This is done so that we can hopefully track change over time. However to get the latest players we can just query on a particular date ("date_generated") to get all players on a particular day.

To connect to Mongo, run the `mongo` command from the command prompt, then `use fpl` to access the FPL database. Finally, the players are then stored in a collection called `players`. So to insert or query use a command like `db.players.findOne()` for example.

Other databases include `teams` and `fixtures` (both of which are fairly self-explanatory).

## More Advanced Queries



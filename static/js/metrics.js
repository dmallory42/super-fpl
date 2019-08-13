/*jshint esversion: 6 */

// These are the metrics we are interested in plotting on the chart:
let tableMetrics = [{
        key: "minutes",
        name: "Minutes Played"
    },
    {
        key: "now_cost",
        name: "Current Value"
    },
    {
        key: "total_points",
        name: "Total Points"
    },
    {
        key: "bps",
        name: "BPS Points"
    },
    {
        key: "bonus",
        name: "Bonus Points"
    },
    {
        key: "goals_scored",
        name: "Goals Scored"
    },
    {
        key: "assists",
        name: "Assists"
    },
    {
        key: "clean_sheets",
        name: "Clean Sheets"
    },
    {
        key: "goals_conceded",
        name: "Goals Conceded",
        reverse: true
    },
    {
        key: "yellow_cards",
        name: "Yellow Cards",
        reverse: true
    },
    {
        key: "ict_index",
        name: "ICT Index",
        reverse: true
    },
    {
        key: "influence",
        name: "Influence"
    },
    {
        key: "creativity",
        name: "Creativity"
    },
    {
        key: "threat",
        name: "Threat"
    },
];

// todo, when we figure out a way of getting positions, we can add templates for each position here:
let templates = {
    goalkeeper: [{
            key: "penalties_saved",
            name: "Penalties Saved",
        },
        {
            key: "saves",
            name: "Saves",
        },
        {
            key: "saves_per_90",
            name: "Saves per 90",
        },
        {
            key: "points_per_90",
            name: "Points per 90",
        },
        {
            key: "points_per_mil",
            name: "Points per Mil",
        },
        {
            key: "bps_per_min",
            name: "BPS per Min",
        },
        {
            key: "bonus",
            name: "Bonus Points",
        },
        {
            key: "goals_conc_per_90",
            name: "Goals Conceded per 90",
            reverse: true
        },
        {
            key: "clean_sheets",
            name: "Clean Sheets",
        },
        {
            key: "clean_sheets_per_90",
            name: "Clean Sheets per 90",
        }
    ],
    defender: [{
            key: "bonus",
            name: "Bonus Points",
        },
        {
            key: "bps_per_min",
            name: "BPS per Min",
        },
        {
            key: "clean_sheets_per_90",
            name: "Clean Sheets per 90",
        },
        {
            key: "goals_conc_per_90",
            name: "Goals Conceded per 90",
            reverse: true
        },
        {
            key: "yellow_cards",
            name: "Yellow Cards",
            reverse: true
        },
        {
            key: "assists_per_90",
            name: "Assists per 90",
        },
        {
            key: "goals_scored_per_90",
            name: "Goals Scored per 90",
        },
        {
            key: "creativity_per_min",
            name: "Creativity per Minute",
        },
        {
            key: "threat_per_min",
            name: "Threat per Minute",
        },
        {
            key: "influence_per_min",
            name: "Influence per Minute",
        },
        {
            key: "points_per_90",
            name: "Points per 90",
        },
        {
            key: "points_per_mil",
            name: "Points per Mil",
        }
    ],
    midfielder: [{
            key: "bonus",
            name: "Bonus Points",
        },
        {
            key: "bps_per_min",
            name: "BPS per Min",
        },
        {
            key: "clean_sheets_per_90",
            name: "Clean Sheets per 90",
        },
        {
            key: "goals_conc_per_90",
            name: "Goals Conceded per 90",
            reverse: true
        },
        {
            key: "assists_per_90",
            name: "Assists per 90",
        },
        {
            key: "goals_scored_per_90",
            name: "Goals Scored per 90",
        },
        {
            key: "creativity_per_min",
            name: "Creativity per Minute",
        },
        {
            key: "threat_per_min",
            name: "Threat per Minute",
        },
        {
            key: "influence_per_min",
            name: "Influence per Minute",
        },
        {
            key: "points_per_90",
            name: "Points per 90",
        },
        {
            key: "points_per_mil",
            name: "Points per Mil",
        }
    ],
    forward: [{
            key: "bonus",
            name: "Bonus Points",
        },
        {
            key: "bps_per_min",
            name: "BPS per Min",
        },
        {
            key: "goals_scored_per_90",
            name: "Goals Scored per 90",
        },
        {
            key: "assists_per_90",
            name: "Assists per 90",
        },
        {
            key: "creativity_per_min",
            name: "Creativity per Minute",
        },
        {
            key: "threat_per_min",
            name: "Threat per Minute",
        },
        {
            key: "influence_per_min",
            name: "Influence per Minute",
        },
        {
            key: "points_per_90",
            name: "Points per 90",
        },
        {
            key: "points_per_mil",
            name: "Points per Mil",
        }
    ],
    generic: [{
            key: "bonus",
            name: "Bonus Points",
        },
        {
            key: "bps_per_min",
            name: "BPS per Min",
        },
        {
            key: "clean_sheets_per_90",
            name: "Clean Sheets per 90",
        },
        {
            key: "goals_conc_per_90",
            name: "Goals Conceded per 90",
            reverse: true
        },
        {
            key: "assists_per_90",
            name: "Assists per 90",
        },
        {
            key: "goals_scored_per_90",
            name: "Goals Scored per 90",
        },
        {
            key: "creativity_per_min",
            name: "Creativity per Minute",
        },
        {
            key: "threat_per_min",
            name: "Threat per Minute",
        },
        {
            key: "influence_per_min",
            name: "Influence per Minute",
        },
        {
            key: "points_per_90",
            name: "Points per 90",
        },
        {
            key: "points_per_mil",
            name: "Points per Mil",
        }
    ]
};
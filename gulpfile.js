/*jshint esversion: 6 */

const { series, parallel, src, dest } = require('gulp');
const sass = require('gulp-sass');
const rename = require('gulp-rename');

sass.compiler = require('node-sass');

function select2css(cb) {
    return src('node_modules/select2/dist/css/select2.css')
        .pipe(dest('static/css'));
}

function select2js(cb) {
    return src('node_modules/select2/dist/js/select2.js')
        .pipe(dest('static/js'));
}
function jquery(cb) {
    return src('node_modules/jquery/dist/jquery.js')
        .pipe(dest('static/js'));
}

function underscore(cb) {
    return src('node_modules/underscore/underscore.js')
        .pipe(dest('static/js'));
}

function chartjs(cb) {
    return src('node_modules/chart.js/dist/Chart.js')
        .pipe(dest('static/js'));
}

function chartjsCss(cb) {
    return src('node_modules/chart.js/dist/Chart.css')
        .pipe(dest('static/css'));
}

// This also imports bulma:
function compileCss(cb) {
    return src('sass/**/*.scss')
        .pipe(sass().on('error', sass.logError))
        .pipe(dest('static/css'));
}

exports.default = series(
    parallel( 
        jquery, 
        select2js, 
        select2css, 
        underscore, 
        chartjs, 
        chartjsCss, 
        compileCss
    )     // compile static assets
);
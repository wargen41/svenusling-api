<?php
namespace App;

use App\Controllers\GenreController;
use App\Controllers\MovieController;
use App\Controllers\SeriesController;
use App\Controllers\MoviePersonsController;
use App\Controllers\MovieGenresController;
use App\Controllers\MovieQuotesController;
use App\Controllers\PersonController;
use App\Controllers\RelationsController;
use App\Controllers\AwardsController;
use App\Controllers\ReviewController;
use App\Controllers\MediaController;
use App\Controllers\AuthController;
use App\Controllers\AdminController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminAuthMiddleware;
use Slim\App;

class Routes
{
    public static function register(App $app)
    {
        // Authentication routes (public)
        $app->post('/auth/register', [AuthController::class, 'register']);
        $app->post('/auth/login', [AuthController::class, 'login']);

        // Genre routes
        $app->get('/genres', [GenreController::class, 'listGenres']);
        $app->get('/genres/{id}', [GenreController::class, 'getGenre']);
        $app->post('/genres', [GenreController::class, 'createGenre'])->add(new AuthMiddleware());
        $app->put('/genres/{id}', [GenreController::class, 'updateGenre'])->add(new AuthMiddleware());
        $app->delete('/genres/{id}', [GenreController::class, 'deleteGenre'])->add(new AuthMiddleware());

        // Movie routes
        $app->get('/movies', [MovieController::class, 'listMovies']);
        $app->get('/movies/{id}', [MovieController::class, 'getMovie']);
        $app->get('/series/{id}', [SeriesController::class, 'getSeries']);
        $app->get('/season/{id}', [SeriesController::class, 'getSeason']);
        $app->post('/movies', [MovieController::class, 'createMovie'])->add(new AuthMiddleware());
        $app->put('/movies/{id}', [MovieController::class, 'updateMovie'])->add(new AuthMiddleware());
        $app->delete('/movies/{id}', [MovieController::class, 'deleteMovie'])->add(new AuthMiddleware());

        // Movie persons endpoints
        $app->get('/movies/{id}/persons', [MoviePersonsController::class, 'getMoviePersons']);
        $app->get('/movies/{id}/persons/{category}', [MoviePersonsController::class, 'getMoviePersonsByCategory']);
        $app->post('/movies/persons', [MoviePersonsController::class, 'addPersonToMovie'])->add(AuthMiddleware::class);
        $app->put('/movies/{movie_id}/persons/{person_id}', [MoviePersonsController::class, 'updatePersonInMovie'])->add(AuthMiddleware::class);
        $app->delete('/movies/{movie_id}/persons/{person_id}', [MoviePersonsController::class, 'removePersonFromMovie'])->add(AuthMiddleware::class);

        // Movie genres endpoints
        $app->get('/movies/{id}/genres', [MovieGenresController::class, 'getMovieGenres']);
        $app->post('/movies/genres', [MovieGenresController::class, 'addGenreToMovie'])->add(AuthMiddleware::class);
        $app->post('/movies/genres/bulk', [MovieGenresController::class, 'addGenresToMovie'])->add(AuthMiddleware::class);
        $app->put('/movies/{id}/genres', [MovieGenresController::class, 'replaceMovieGenres'])->add(AuthMiddleware::class);
        $app->delete('/movies/{movie_id}/genres/{genre_id}', [MovieGenresController::class, 'removeGenreFromMovie'])->add(AuthMiddleware::class);

        // Movie media endpoints
        $app->get('/movies/{id}/media', [MovieController::class, 'getMovieMedia']);
        $app->post('/movies/media', [MovieController::class, 'addMediaToMovie'])->add(AuthMiddleware::class);
        $app->post('/movies/media/bulk', [MovieController::class, 'addMultipleMediaToMovie'])->add(AuthMiddleware::class);
        $app->put('/movies/{id}/media', [MovieController::class, 'replaceMovieMedia'])->add(AuthMiddleware::class);
        $app->delete('/movies/{movie_id}/media/{media_id}', [MovieController::class, 'removeMediaFromMovie'])->add(AuthMiddleware::class);

        // Movie quotes endpoints
        $app->get('/movies/{id}/quotes', [MovieQuotesController::class, 'getMovieQuotes']);
        $app->get('/quotes/{quote_id}', [MovieQuotesController::class, 'getQuote']);
        $app->post('/movies/quotes', [MovieQuotesController::class, 'addQuote'])->add(AuthMiddleware::class);
        $app->post('/movies/quotes/bulk', [MovieQuotesController::class, 'addMultipleQuotes'])->add(AuthMiddleware::class);
        $app->put('/quotes/{quote_id}', [MovieQuotesController::class, 'updateQuote'])->add(AuthMiddleware::class);
        $app->delete('/quotes/{quote_id}', [MovieQuotesController::class, 'deleteQuote'])->add(AuthMiddleware::class);
        $app->delete('/movies/{id}/quotes', [MovieQuotesController::class, 'deleteAllMovieQuotes'])->add(AuthMiddleware::class);

        // Person routes
        $app->get('/persons', [PersonController::class, 'listPersons']);
        $app->get('/persons/{id}', [PersonController::class, 'getPerson']);
        $app->post('/persons', [PersonController::class, 'createPerson'])->add(new AuthMiddleware());
        $app->put('/persons/{id}', [PersonController::class, 'updatePerson'])->add(new AuthMiddleware());
        $app->delete('/persons/{id}', [PersonController::class, 'deletePerson'])->add(new AuthMiddleware());

        // Relations endpoints
        $app->get('/relations', [RelationsController::class, 'listRelationTypes']);
        $app->get('/relations/{id}', [RelationsController::class, 'getRelationType']);
        $app->post('/relations', [RelationsController::class, 'createRelationType'])->add(AuthMiddleware::class);
        $app->put('/relations/{id}', [RelationsController::class, 'updateRelationType'])->add(AuthMiddleware::class);
        $app->delete('/relations/{id}', [RelationsController::class, 'deleteRelationType'])->add(AuthMiddleware::class);

        // Person relations endpoints
        $app->get('/persons/{id}/relations', [RelationsController::class, 'getPersonRelations']);
        $app->post('/persons/relations', [RelationsController::class, 'createPersonRelation'])->add(AuthMiddleware::class);
        $app->put('/persons/{person_id}/relations/{relation_id}', [RelationsController::class, 'updatePersonRelation'])->add(AuthMiddleware::class);
        $app->delete('/persons/{person_id}/relations/{relation_id}', [RelationsController::class, 'deletePersonRelation'])->add(AuthMiddleware::class);

        // Person media endpoints
        $app->get('/persons/{id}/media', [PersonController::class, 'getPersonMedia']);
        $app->post('/persons/media', [PersonController::class, 'addMediaToPerson'])->add(AuthMiddleware::class);
        $app->post('/persons/media/bulk', [PersonController::class, 'addMultipleMediaToPerson'])->add(AuthMiddleware::class);
        $app->put('/persons/{id}/media', [PersonController::class, 'replacePersonMedia'])->add(AuthMiddleware::class);
        $app->delete('/persons/{person_id}/media/{media_id}', [PersonController::class, 'removeMediaFromPerson'])->add(AuthMiddleware::class);

        // Awards endpoints
        $app->get('/awards', [AwardsController::class, 'listAwards']);
        $app->get('/awards/categories', [AwardsController::class, 'listCategories']);
        $app->get('/awards/{id}', [AwardsController::class, 'getAward']);
        $app->post('/awards', [AwardsController::class, 'createAward'])->add(AuthMiddleware::class);
        $app->put('/awards/{id}', [AwardsController::class, 'updateAward'])->add(AuthMiddleware::class);
        $app->delete('/awards/{id}', [AwardsController::class, 'deleteAward'])->add(AuthMiddleware::class);

        // Award nominations
        $app->post('/awards/nominations/movies', [AwardsController::class, 'addMovieNomination'])->add(AuthMiddleware::class);
        $app->post('/awards/nominations/persons', [AwardsController::class, 'addPersonNomination'])->add(AuthMiddleware::class);
        $app->put('/awards/{award_id}/movies/{movie_id}', [AwardsController::class, 'updateMovieNomination'])->add(AuthMiddleware::class);
        $app->put('/awards/{award_id}/persons/{person_id}', [AwardsController::class, 'updatePersonNomination'])->add(AuthMiddleware::class);
        $app->delete('/awards/{award_id}/movies/{movie_id}', [AwardsController::class, 'deleteMovieNomination'])->add(AuthMiddleware::class);
        $app->delete('/awards/{award_id}/persons/{person_id}', [AwardsController::class, 'deletePersonNomination'])->add(AuthMiddleware::class);

        // Review routes (public read)
        // (Reviews shown with movie details)

        // Review routes (protected - authenticated users)
        $app->post('/reviews', [ReviewController::class, 'addReview'])->add(new AuthMiddleware());
        $app->put('/reviews/{id}', [ReviewController::class, 'updateReview'])->add(new AuthMiddleware());
        $app->delete('/reviews/{id}', [ReviewController::class, 'deleteReview'])->add(new AuthMiddleware());

        // Media routes (public read)
        $app->get('/media', [MediaController::class, 'listMedia']);
        $app->get('/media/{id}', [MediaController::class, 'getMedia']);

        // Media routes (protected - admin only)
        $app->post('/media', [MediaController::class, 'createMedia'])->add(new AuthMiddleware());
        $app->put('/media/{id}', [MediaController::class, 'updateMedia'])->add(new AuthMiddleware());
        $app->delete('/media/{id}', [MediaController::class, 'deleteMedia'])->add(new AuthMiddleware());

        // Admin pages
        $app->get('/admin', [AdminController::class, 'dashboard'])->add(new AdminAuthMiddleware());
        $app->get('/admin/login', [AdminController::class, 'loginPage']);
        $app->post('/admin/login', [AdminController::class, 'handleLogin']);
        $app->get('/admin/logout', [AdminController::class, 'logout']);

        // Other admin pages (protected)
        $app->get('/admin/movies', [AdminController::class, 'moviesPage'])->add(new AdminAuthMiddleware());
        $app->get('/admin/persons', [AdminController::class, 'personsPage'])->add(new AdminAuthMiddleware());
        // etc.
    }
}

<?php
namespace App;

use App\Controllers\GenreController;
use App\Controllers\MovieController;
use App\Controllers\SeriesController;
use App\Controllers\MoviePersonsController;
use App\Controllers\MovieGenresController;
use App\Controllers\PersonController;
use App\Controllers\RelationsController;
use App\Controllers\AwardsController;
use App\Controllers\ReviewController;
use App\Controllers\MediaController;
use App\Controllers\AuthController;
use App\Middleware\AuthMiddleware;
use Slim\App;

class Routes
{
    public static function register(App $app)
    {
        // Authentication routes (public)
        $app->post('/api/auth/register', [AuthController::class, 'register']);
        $app->post('/api/auth/login', [AuthController::class, 'login']);

        // Genre routes
        $app->get('/api/genres', [GenreController::class, 'listGenres']);
        $app->get('/api/genres/{id}', [GenreController::class, 'getGenre']);
        $app->post('/api/genres', [GenreController::class, 'createGenre'])->add(new AuthMiddleware());
        $app->put('/api/genres/{id}', [GenreController::class, 'updateGenre'])->add(new AuthMiddleware());
        $app->delete('/api/genres/{id}', [GenreController::class, 'deleteGenre'])->add(new AuthMiddleware());

        // Movie routes
        $app->get('/api/movies', [MovieController::class, 'listMovies']);
        $app->get('/api/movies/{id}', [MovieController::class, 'getMovie']);
        $app->get('/api/series/{id}', [SeriesController::class, 'getSeries']);
        $app->get('/api/season/{id}', [SeriesController::class, 'getSeason']);
        $app->post('/api/movies', [MovieController::class, 'createMovie'])->add(new AuthMiddleware());
        $app->put('/api/movies/{id}', [MovieController::class, 'updateMovie'])->add(new AuthMiddleware());
        $app->delete('/api/movies/{id}', [MovieController::class, 'deleteMovie'])->add(new AuthMiddleware());

        // Movie persons endpoints
        $app->get('/api/movies/{id}/persons', [MoviePersonsController::class, 'getMoviePersons']);
        $app->get('/api/movies/{id}/persons/{category}', [MoviePersonsController::class, 'getMoviePersonsByCategory']);
        $app->post('/api/movies/persons', [MoviePersonsController::class, 'addPersonToMovie'])->add(AuthMiddleware::class);
        $app->put('/api/movies/{movie_id}/persons/{person_id}', [MoviePersonsController::class, 'updatePersonInMovie'])->add(AuthMiddleware::class);
        $app->delete('/api/movies/{movie_id}/persons/{person_id}', [MoviePersonsController::class, 'removePersonFromMovie'])->add(AuthMiddleware::class);

        // Movie genres endpoints
        $app->get('/api/movies/{id}/genres', [MovieGenresController::class, 'getMovieGenres']);
        $app->post('/api/movies/genres', [MovieGenresController::class, 'addGenreToMovie'])->add(AuthMiddleware::class);
        $app->post('/api/movies/genres/bulk', [MovieGenresController::class, 'addGenresToMovie'])->add(AuthMiddleware::class);
        $app->put('/api/movies/{id}/genres', [MovieGenresController::class, 'replaceMovieGenres'])->add(AuthMiddleware::class);
        $app->delete('/api/movies/{movie_id}/genres/{genre_id}', [MovieGenresController::class, 'removeGenreFromMovie'])->add(AuthMiddleware::class);

        // Movie media endpoints
        $app->get('/api/movies/{id}/media', [MovieController::class, 'getMovieMedia']);
        $app->post('/api/movies/media', [MovieController::class, 'addMediaToMovie'])->add(AuthMiddleware::class);
        $app->post('/api/movies/media/bulk', [MovieController::class, 'addMultipleMediaToMovie'])->add(AuthMiddleware::class);
        $app->put('/api/movies/{id}/media', [MovieController::class, 'replaceMovieMedia'])->add(AuthMiddleware::class);
        $app->delete('/api/movies/{movie_id}/media/{media_id}', [MovieController::class, 'removeMediaFromMovie'])->add(AuthMiddleware::class);

        // Person routes
        $app->get('/api/persons', [PersonController::class, 'listPersons']);
        $app->get('/api/persons/{id}', [PersonController::class, 'getPerson']);
        $app->post('/api/persons', [PersonController::class, 'createPerson'])->add(new AuthMiddleware());
        $app->put('/api/persons/{id}', [PersonController::class, 'updatePerson'])->add(new AuthMiddleware());
        $app->delete('/api/persons/{id}', [PersonController::class, 'deletePerson'])->add(new AuthMiddleware());

        // Relations endpoints
        $app->get('/api/relations', [RelationsController::class, 'listRelationTypes']);
        $app->get('/api/relations/{id}', [RelationsController::class, 'getRelationType']);
        $app->post('/api/relations', [RelationsController::class, 'createRelationType'])->add(AuthMiddleware::class);
        $app->put('/api/relations/{id}', [RelationsController::class, 'updateRelationType'])->add(AuthMiddleware::class);
        $app->delete('/api/relations/{id}', [RelationsController::class, 'deleteRelationType'])->add(AuthMiddleware::class);

        // Person relations endpoints
        $app->get('/api/persons/{id}/relations', [RelationsController::class, 'getPersonRelations']);
        $app->post('/api/persons/relations', [RelationsController::class, 'createPersonRelation'])->add(AuthMiddleware::class);
        $app->put('/api/persons/{person_id}/relations/{relation_id}', [RelationsController::class, 'updatePersonRelation'])->add(AuthMiddleware::class);
        $app->delete('/api/persons/{person_id}/relations/{relation_id}', [RelationsController::class, 'deletePersonRelation'])->add(AuthMiddleware::class);

        // Person media endpoints
        $app->get('/api/persons/{id}/media', [PersonController::class, 'getPersonMedia']);
        $app->post('/api/persons/media', [PersonController::class, 'addMediaToPerson'])->add(AuthMiddleware::class);
        $app->post('/api/persons/media/bulk', [PersonController::class, 'addMultipleMediaToPerson'])->add(AuthMiddleware::class);
        $app->put('/api/persons/{id}/media', [PersonController::class, 'replacePersonMedia'])->add(AuthMiddleware::class);
        $app->delete('/api/persons/{person_id}/media/{media_id}', [PersonController::class, 'removeMediaFromPerson'])->add(AuthMiddleware::class);

        // Awards endpoints
        $app->get('/api/awards', [AwardsController::class, 'listAwards']);
        $app->get('/api/awards/categories', [AwardsController::class, 'listCategories']);
        $app->get('/api/awards/{id}', [AwardsController::class, 'getAward']);
        $app->post('/api/awards', [AwardsController::class, 'createAward'])->add(AuthMiddleware::class);
        $app->put('/api/awards/{id}', [AwardsController::class, 'updateAward'])->add(AuthMiddleware::class);
        $app->delete('/api/awards/{id}', [AwardsController::class, 'deleteAward'])->add(AuthMiddleware::class);

        // Award nominations
        $app->post('/api/awards/nominations/movies', [AwardsController::class, 'addMovieNomination'])->add(AuthMiddleware::class);
        $app->post('/api/awards/nominations/persons', [AwardsController::class, 'addPersonNomination'])->add(AuthMiddleware::class);
        $app->put('/api/awards/{award_id}/movies/{movie_id}', [AwardsController::class, 'updateMovieNomination'])->add(AuthMiddleware::class);
        $app->put('/api/awards/{award_id}/persons/{person_id}', [AwardsController::class, 'updatePersonNomination'])->add(AuthMiddleware::class);
        $app->delete('/api/awards/{award_id}/movies/{movie_id}', [AwardsController::class, 'deleteMovieNomination'])->add(AuthMiddleware::class);
        $app->delete('/api/awards/{award_id}/persons/{person_id}', [AwardsController::class, 'deletePersonNomination'])->add(AuthMiddleware::class);

        // Review routes (public read)
        // (Reviews shown with movie details)

        // Review routes (protected - authenticated users)
        $app->post('/api/reviews', [ReviewController::class, 'addReview'])->add(new AuthMiddleware());
        $app->put('/api/reviews/{id}', [ReviewController::class, 'updateReview'])->add(new AuthMiddleware());
        $app->delete('/api/reviews/{id}', [ReviewController::class, 'deleteReview'])->add(new AuthMiddleware());

        // Media routes (public read)
        $app->get('/api/media', [MediaController::class, 'listMedia']);
        $app->get('/api/media/{id}', [MediaController::class, 'getMedia']);

        // Media routes (protected - admin only)
        $app->post('/api/media', [MediaController::class, 'createMedia'])->add(new AuthMiddleware());
        $app->put('/api/media/{id}', [MediaController::class, 'updateMedia'])->add(new AuthMiddleware());
        $app->delete('/api/media/{id}', [MediaController::class, 'deleteMedia'])->add(new AuthMiddleware());
    }
}

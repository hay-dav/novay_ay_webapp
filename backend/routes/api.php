<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ArticleLessonController;
use App\Http\Controllers\Api\AccessRequestController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\FoodDiaryController;
use App\Http\Controllers\Api\LessonProgressController;
use App\Http\Controllers\Api\LessonQuestionController;
use App\Http\Controllers\Api\LiveStreamController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NutritionPlanController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\PodcastController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\TrainerDashboardController;
use App\Http\Controllers\Api\WorkoutController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/workouts/{workout}/stream', [WorkoutController::class, 'stream'])
        ->middleware('signed')
        ->name('workouts.stream');
    Route::get('/workouts/{workout}/download', [WorkoutController::class, 'download'])
        ->middleware('signed')
        ->name('workouts.download');
    Route::get('/podcasts/{podcast}/stream', [PodcastController::class, 'stream'])
        ->middleware('signed')
        ->name('podcasts.stream');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/avatar', [AuthController::class, 'updateAvatar']);

        Route::get('/article-lessons', [ArticleLessonController::class, 'index']);
        Route::post('/article-lessons', [ArticleLessonController::class, 'store']);
        Route::patch('/article-lessons/{lesson}', [ArticleLessonController::class, 'update']);
        Route::delete('/article-lessons/{lesson}', [ArticleLessonController::class, 'destroy']);
        Route::get('/podcasts', [PodcastController::class, 'index']);
        Route::post('/podcasts', [PodcastController::class, 'store']);
        Route::delete('/podcasts/{podcast}', [PodcastController::class, 'destroy']);

        Route::get('/courses', [CourseController::class, 'index']);
        Route::get('/course-materials', [CourseController::class, 'materials']);
        Route::get('/courses/{slug}', [CourseController::class, 'show']);
        Route::post('/courses/{course}/purchase', [PurchaseController::class, 'store']);
        Route::post('/course-materials', [CourseController::class, 'storeMaterial']);

        Route::get('/purchases', [PurchaseController::class, 'index']);

        Route::patch('/lessons/{lesson}/progress', [LessonProgressController::class, 'update']);
        Route::post('/lessons/{lesson}/questions', [LessonQuestionController::class, 'store']);
        Route::patch('/lesson-questions/{question}/answer', [LessonQuestionController::class, 'answer']);

        Route::get('/nutrition-plans/current', [NutritionPlanController::class, 'current']);
        Route::get('/food-diary', [FoodDiaryController::class, 'index']);
        Route::post('/food-diary', [FoodDiaryController::class, 'store']);
        Route::patch('/food-diary/goals', [FoodDiaryController::class, 'updateGoals']);
        Route::get('/food-diary/comments', [FoodDiaryController::class, 'comments']);
        Route::get('/recipes', [RecipeController::class, 'index']);
        Route::post('/recipes', [RecipeController::class, 'store']);
        Route::get('/workouts', [WorkoutController::class, 'index']);
        Route::get('/workouts/summary', [WorkoutController::class, 'summary']);
        Route::post('/workouts', [WorkoutController::class, 'store']);
        Route::patch('/workouts/{workout}', [WorkoutController::class, 'update']);
        Route::delete('/workouts/{workout}', [WorkoutController::class, 'destroy']);
        Route::post('/workouts/{workout}/complete', [WorkoutController::class, 'complete']);
        Route::get('/live-streams/active', [LiveStreamController::class, 'active']);
        Route::post('/live-streams/start', [LiveStreamController::class, 'start']);
        Route::patch('/live-streams/{stream}/end', [LiveStreamController::class, 'end']);
        Route::post('/live-streams/{stream}/heartbeat', [LiveStreamController::class, 'heartbeat']);
        Route::post('/live-streams/{stream}/recording', [LiveStreamController::class, 'storeRecording']);
        Route::post('/live-streams/{stream}/token', [LiveStreamController::class, 'token']);
        Route::get('/live-streams/{stream}/viewers', [LiveStreamController::class, 'viewers']);
        Route::post('/progress', [ProgressController::class, 'store']);
        Route::get('/progress', [ProgressController::class, 'index']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/chat/messages', [ChatController::class, 'index']);
        Route::post('/chat/messages', [ChatController::class, 'store']);
        Route::get('/chat/peers', [ChatController::class, 'peers']);
        Route::get('/access-requests', [AccessRequestController::class, 'index']);
        Route::post('/access-requests', [AccessRequestController::class, 'store']);
        Route::patch('/access-requests/{accessRequest}/approve', [AccessRequestController::class, 'approve']);

        Route::get('/trainer/dashboard', TrainerDashboardController::class);
        Route::post('/trainer/courses', [CourseController::class, 'store']);
        Route::post('/trainer/clients/{client}/nutrition-plans', [NutritionPlanController::class, 'storeForClient']);
        Route::get('/admin/dashboard', [AdminDashboardController::class, 'index']);
        Route::patch('/admin/users/{user}', [AdminDashboardController::class, 'updateUser']);
        Route::get('/admin/users/{user}/details', [AdminDashboardController::class, 'clientDetails']);
        Route::post('/admin/users/{user}/comments', [AdminDashboardController::class, 'storeClientComment']);
        Route::post('/admin/notifications', [AdminDashboardController::class, 'sendNotification']);
    });
});

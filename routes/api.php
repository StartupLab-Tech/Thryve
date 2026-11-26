<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AgentAccessController;
use App\Http\Controllers\AgentAvatarController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AnalysisReportController;
use App\Http\Controllers\AnalysisTopicController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AttendanceAdminController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\ModelChoiceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SessionAdminController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\UsageController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\PrepareChatRequest;
use App\Http\Responses\Api;
use Illuminate\Support\Facades\Route;

// Public: liveness for the SPA/installer. No secrets or dependency details.
Route::get('/health', fn () => Api::ok(['status' => 'ok']));

// Installer: exists only until installation finishes (404 afterwards).
Route::prefix('install')->middleware(['not-installed', 'throttle:60,1'])->group(function () {
    Route::get('/checks', [InstallController::class, 'checks']);
    Route::post('/test-database', [InstallController::class, 'testDatabase']);
    Route::post('/test-hermes', [InstallController::class, 'testHermes']);
    Route::post('/run', [InstallController::class, 'run']);
});

// Agents calling in with their own key: no browser session, no cookies. One tiny endpoint, rate limited.
Route::middleware(['installed', 'throttle:120,1'])->get('/agent/ping', [AgentController::class, 'ping']);

// Everything else needs a finished installation and uses the cookie session ('web' group = session + CSRF).
Route::middleware(['web', 'installed'])->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
    Route::get('/branding', [BrandingController::class, 'show']);

    // Authenticated boundary: signed in, active, and still the account's single current session.
    Route::middleware(['auth', 'current-session', 'db-current'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/impersonation/stop', [ImpersonationController::class, 'stop']); // back to the Super Admin's own account

        // Closed while a Super Admin is viewing as this person: these are the employee's own.
        Route::post('/account/password', [AccountController::class, 'changePassword'])->middleware('not-viewing-as');
        Route::get('/policy', [PolicyController::class, 'show']);
        Route::post('/policy/acknowledge', [PolicyController::class, 'acknowledge'])->middleware('not-viewing-as');

        // Everything below needs the current usage policy to be acknowledged first.
        // The password comes first, then the policy: someone still on an admin-given password sees only the change screen.
        Route::middleware(['password-changed', 'policy'])->group(function () {

            // Own AI preferences. Closed while a Super Admin is viewing as this person: nobody else reads or changes them.
            Route::get('/account/personalization', [AccountController::class, 'showPersonalization'])->middleware('not-viewing-as');
            Route::put('/account/personalization', [AccountController::class, 'updatePersonalization'])->middleware(['not-viewing-as', 'throttle:30,1']);

            // Own notifications (the bell). Scoped to the signed-in user in the controller.
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
            Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id');

            // Own conversations (private to the signed-in person).
            Route::get('/chat/agents', [ChatController::class, 'agents']);
            Route::get('/chat/models', [ChatController::class, 'models']); // the approved models this person may pick for a chatbot
            Route::get('/agents/{id}/avatar', [AgentAvatarController::class, 'show'])->whereNumber('id'); // the optional chatbot picture
            Route::get('/conversations', [ChatController::class, 'index']);
            Route::post('/chat', [ChatController::class, 'send'])->middleware(['throttle:chat-send', PrepareChatRequest::class]);
            Route::post('/chat/streams/{stream}/stop', [ChatController::class, 'stop'])->middleware('throttle:chat-control')->whereUuid('stream');
            Route::get('/conversations/{id}', [ChatController::class, 'show'])->whereNumber('id');
            Route::patch('/conversations/{id}', [ChatController::class, 'update'])->whereNumber('id');
            Route::delete('/conversations/{id}', [ChatController::class, 'destroy'])->whereNumber('id');
            Route::post('/conversations/{id}/regenerate', [ChatController::class, 'regenerate'])->whereNumber('id')->middleware(['throttle:chat-send', PrepareChatRequest::class]);
            Route::patch('/conversations/{id}/messages/{messageId}', [ChatController::class, 'edit'])->whereNumber(['id', 'messageId'])->middleware(['throttle:chat-send', PrepareChatRequest::class]);
            Route::post('/conversations/{id}/messages/{messageId}/rating', [ChatController::class, 'rate'])->whereNumber(['id', 'messageId']);

            // Chat attachments: own files only.
            Route::get('/attachments/limits', [AttachmentController::class, 'limits']);
            Route::post('/attachments', [AttachmentController::class, 'store'])->middleware('throttle:60,1');
            Route::delete('/attachments/{id}', [AttachmentController::class, 'destroy'])->whereNumber('id');
            Route::get('/attachments/{id}/download', [AttachmentController::class, 'download'])->whereNumber('id');

            // Own token allowance (never any provider detail).
            Route::get('/usage/today', [UsageController::class, 'mine']);

            // Own prompt scores (never anyone else's; the server uses the signed-in person).
            Route::get('/analysis/mine', [AnalysisReportController::class, 'mine'])->middleware('throttle:reports-read');

            // Own attendance (never anyone else's).
            Route::post('/attendance/heartbeat', [AttendanceController::class, 'heartbeat'])->middleware('throttle:20,1');
            Route::post('/attendance/close', [AttendanceController::class, 'close']);
            Route::get('/attendance/me', [AttendanceController::class, 'mine']);

            // Account management: Admin and Super Admin; finer limits (who may touch whom) live in UserPolicy.
            Route::middleware('role:admin,super_admin')->group(function () {
                Route::prefix('reports')->middleware('throttle:reports-read')->group(function () {
                    Route::get('/overview', [ReportController::class, 'overview']);
                    Route::get('/people', [ReportController::class, 'people']);
                    Route::get('/attendance', [ReportController::class, 'attendance']);
                    Route::get('/filters', [ReportController::class, 'filters']);
                    Route::get('/usage', [ReportController::class, 'usage']);
                    Route::get('/quality', [ReportController::class, 'quality']);
                    Route::get('/adoption', [ReportController::class, 'adoption']);
                    Route::get('/analysis', [AnalysisReportController::class, 'report']);
                    Route::get('/conversations', [ReportController::class, 'conversations']);
                    Route::get('/conversations/{conversation}', [ReportController::class, 'inspect'])->whereNumber('conversation');
                    Route::get('/conversations/{conversation}/attachments/{attachment}', [ReportController::class, 'download'])->whereNumber(['conversation', 'attachment']);
                    Route::get('/audit', [ReportController::class, 'audit']);
                    Route::get('/export', [ReportController::class, 'export'])->middleware('throttle:reports-export');
                });
                // The topic list the report agent chooses from.
                Route::get('/analysis/topics', [AnalysisTopicController::class, 'index']);
                Route::post('/analysis/topics', [AnalysisTopicController::class, 'store']);
                Route::patch('/analysis/topics/{id}', [AnalysisTopicController::class, 'update'])->whereNumber('id');
                Route::get('/users', [UserController::class, 'index']);
                Route::post('/users', [UserController::class, 'store']);
                Route::get('/user-agent-options', [AgentAccessController::class, 'options']);
                Route::get('/users/{user}', [UserController::class, 'show']);
                Route::patch('/users/{user}', [UserController::class, 'update']);
                Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
                Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate']);
                Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
                Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start']); // Super Admin only (UserPolicy)
                Route::get('/users/{user}/agents', [AgentAccessController::class, 'show']);
                Route::put('/users/{user}/agents', [AgentAccessController::class, 'update']);

                // Providers and usage: Admins monitor (read-only); only Super Admin changes anything (group below).
                Route::get('/agents', [AgentController::class, 'index']);
                Route::get('/agents/{id}/checks', [AgentController::class, 'checks'])->whereNumber('id');
                Route::get('/model-choices', [ModelChoiceController::class, 'index']);
                Route::get('/usage/summary', [UsageController::class, 'summary']);
                Route::post('/users/{user}/token-grants', [UsageController::class, 'grant']);

                Route::get('/attendance', [AttendanceAdminController::class, 'index']);
                Route::post('/attendance/corrections', [AttendanceAdminController::class, 'correct']);
                Route::get('/attendance/days/{day}/corrections', [AttendanceAdminController::class, 'history']);

                Route::get('/departments', [DepartmentController::class, 'index']);
                Route::post('/departments', [DepartmentController::class, 'store']);
                Route::patch('/departments/{department}', [DepartmentController::class, 'update']);
                Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);
            });

            Route::post('/users/{user}/terminate-session', [SessionAdminController::class, 'terminate'])->middleware('role:super_admin');

            Route::middleware('role:super_admin')->group(function () {
                Route::get('/settings', [SettingsController::class, 'index']);
                Route::patch('/settings', [SettingsController::class, 'update']);
                Route::post('/settings/smtp-test', [SettingsController::class, 'smtpTest'])->middleware('throttle:10,1');
                Route::post('/settings/branding', [BrandingController::class, 'upload']);
                Route::delete('/settings/branding/{kind}', [BrandingController::class, 'destroy']);
                Route::get('/settings/transfer', [TransferController::class, 'show']);
                Route::post('/settings/transfer/chats', [TransferController::class, 'chats'])->middleware('throttle:transfer-chats');
                Route::post('/settings/transfer/key', [TransferController::class, 'key'])->middleware('throttle:transfer-key');
                Route::post('/agents', [AgentController::class, 'store']);
                Route::post('/agents/{id}/avatar', [AgentAvatarController::class, 'upload'])->whereNumber('id');
                Route::delete('/agents/{id}/avatar', [AgentAvatarController::class, 'destroy'])->whereNumber('id');
                Route::patch('/agents/{id}', [AgentController::class, 'update'])->whereNumber('id');
                Route::delete('/agents/{id}', [AgentController::class, 'destroy'])->whereNumber('id');
                Route::post('/agents/{id}/test', [AgentController::class, 'test'])->whereNumber('id')->middleware('throttle:20,1');
                Route::post('/agents/{id}/rotate', [AgentController::class, 'rotate'])->whereNumber('id');
                Route::post('/agents/{id}/rotate/activate', [AgentController::class, 'activate'])->whereNumber('id')->middleware('throttle:20,1');
                Route::delete('/agents/{id}/rotate', [AgentController::class, 'cancelRotation'])->whereNumber('id');
                Route::post('/agents/{id}/revoke', [AgentController::class, 'revoke'])->whereNumber('id');
                Route::post('/agents/{id}/inbound-key', [AgentController::class, 'inboundKey'])->whereNumber('id');
                Route::post('/model-choices', [ModelChoiceController::class, 'store']);
                Route::patch('/model-choices/{id}', [ModelChoiceController::class, 'update'])->whereNumber('id');

                Route::get('/system/health', [SystemController::class, 'health']);

                // Updates and backups (maintenance mode lets this browser keep working while others see the notice).
                Route::get('/system/updates', [UpdateController::class, 'status']);
                Route::post('/system/updates/stage', [UpdateController::class, 'stage'])->middleware('throttle:10,1');
                Route::post('/system/updates/approve', [UpdateController::class, 'approve']);
                Route::post('/system/updates/database', [UpdateController::class, 'approveDatabase']); // new code was deployed: bring the database up to date
                Route::post('/system/updates/advance', [UpdateController::class, 'advance']);
                Route::post('/system/updates/rollback', [UpdateController::class, 'rollback']);
                Route::delete('/system/updates/staged', [UpdateController::class, 'discard']);
                Route::get('/system/backups', [UpdateController::class, 'backups']);
                Route::post('/system/backups', [UpdateController::class, 'backupNow'])->middleware('throttle:6,1');
                Route::get('/system/backups/{id}/verify', [UpdateController::class, 'verifyBackup']);
            });

            // Feature routes are added here by their owning phase.

        }); // policy
    });
});

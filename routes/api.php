<?php

use App\Http\Controllers\Api\V1\AiSettingsController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CannedResponseController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\PrivacyController;
use App\Http\Controllers\Api\V1\PushController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RatingController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SlaAnalyticsController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\TicketAiController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\InboundEmailController;
use App\Http\Controllers\Api\V1\InternalTickController;
use App\Http\Controllers\Api\V1\AutomationRuleController;
use App\Http\Controllers\Api\V1\BulkTicketController;
use App\Http\Controllers\Api\V1\CustomerSlaController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SatisfactionController;
use App\Http\Controllers\Api\V1\SavedViewController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\TicketCollaborationController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\TicketInsightsController;
use App\Http\Controllers\Api\V1\TicketMessageController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - DeskFlow Enterprise V1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // 1. Authentication (Public)
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:otp');
        Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp');
        Route::get('/invite-info', [AuthController::class, 'inviteInfo'])->middleware('throttle:otp');
        Route::post('/accept-invite', [AuthController::class, 'acceptInvite'])->middleware('throttle:otp');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:otp');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:otp');
        Route::post('/resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:otp');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('/two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:login');
    });

    // External scheduler tick (runs due scheduled tasks + drains the queue). Header-secret protected; off unless CRON_SECRET is set.
    Route::post('/internal/tick', [InternalTickController::class, 'tick'])->middleware('throttle:30,1');

    // Mail-provider webhook (shared-secret authenticated inside the controller)
    Route::post('/webhooks/inbound-email', [InboundEmailController::class, 'receive'])->middleware('throttle:120,1');

    // Support assistant widget (guests: help questions only; signed-in customers: plus own tickets)
    Route::post('/assistant/chat', [AssistantController::class, 'chat'])->middleware('throttle:assistant');

    // Public help centre (published articles only)
    Route::get('/kb/articles', [ArticleController::class, 'search'])->middleware('throttle:60,1');
    Route::get('/kb/categories', [ArticleController::class, 'categories'])->middleware('throttle:60,1');
    Route::get('/kb/articles/{slug}', [ArticleController::class, 'show'])->middleware('throttle:60,1');
    Route::post('/kb/articles/{slug}/vote', [ArticleController::class, 'vote'])->middleware('throttle:20,1');

    // 3. Authenticated Routes (Sanctum Protected)
    Route::middleware('auth:sanctum')->group(function () {

        // Session
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Password and signed-in devices
        Route::patch('/auth/password', [AuthController::class, 'changePassword'])->middleware('throttle:10,1');
        Route::get('/auth/sessions', [AuthController::class, 'sessions']);
        Route::post('/auth/sessions/revoke-others', [AuthController::class, 'revokeOtherSessions']);
        Route::delete('/auth/sessions/{id}', [AuthController::class, 'revokeSession'])->whereNumber('id');

        // Profile: display name and photo (photo goes browser -> Cloudinary with a signed upload)
        Route::patch('/auth/profile', [ProfileController::class, 'update'])->middleware('throttle:20,1');
        Route::post('/auth/avatar/sign', [ProfileController::class, 'signAvatar'])->middleware('throttle:20,1');
        Route::post('/auth/avatar', [ProfileController::class, 'setAvatar'])->middleware('throttle:20,1');
        Route::delete('/auth/avatar', [ProfileController::class, 'removeAvatar'])->middleware('throttle:20,1');

        // Department agents
        Route::get('/departments', [DepartmentController::class, 'index']);
        Route::get('/departments/{department}/agents', [DepartmentController::class, 'agents']);

        // Ticket Core Operations
        Route::get('/tickets-similar', [TicketInsightsController::class, 'openMatches'])->middleware('throttle:30,1');
        Route::get('/me/sla', [CustomerSlaController::class, 'show']);
        Route::get('/tickets', [TicketController::class, 'index']);
        Route::post('/tickets', [TicketController::class, 'store']);
        Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
        Route::patch('/tickets/{ticket}/status', [TicketController::class, 'updateStatus']);
        Route::post('/tickets/{ticket}/presence', [TicketController::class, 'pingPresence']);

        // Two-factor authentication (optional, authenticator app)
        Route::post('/2fa/setup', [TwoFactorController::class, 'setup']);
        Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/2fa/disable', [TwoFactorController::class, 'disable']);

        // Privacy (GDPR): export my data / erase my account
        Route::get('/me/export', [PrivacyController::class, 'export']);
        Route::delete('/me', [PrivacyController::class, 'erase']);

        // Customer satisfaction
        Route::post('/tickets/{ticket}/rating', [RatingController::class, 'store']);

        // In-app notifications (bell)
        Route::post('/rich-text/preview', [\App\Http\Controllers\Api\V1\RichTextController::class, 'preview'])->middleware('throttle:60,1');
        Route::get('/push/key', [PushController::class, 'key']);
        Route::post('/push/subscribe', [PushController::class, 'subscribe'])->middleware('throttle:20,1');
        Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe'])->middleware('throttle:20,1');
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);

        // Direct-to-Cloudinary upload signing
        Route::post('/attachments/sign', [AttachmentController::class, 'sign'])->middleware('throttle:60,1');

        // Messages & Collaboration
        Route::post('/tickets/{ticket}/messages', [TicketMessageController::class, 'store']);

        // Staff-only Operations (Agent, Lead, Admin)
        Route::middleware('role:agent,lead,admin')->group(function () {
            Route::post('/tickets/bulk', BulkTicketController::class);
            Route::get('/saved-views', [SavedViewController::class, 'index']);
            Route::post('/saved-views', [SavedViewController::class, 'store']);
            Route::delete('/saved-views/{view}', [SavedViewController::class, 'destroy']);
            Route::patch('/tickets/{ticket}/assign', [TicketController::class, 'assignAgent']);
            Route::patch('/tickets/{ticket}/priority', [TicketController::class, 'updatePriority']);
            Route::post('/tickets/{ticket}/ai/draft-reply', [TicketAiController::class, 'draft'])->middleware('throttle:ai-staff');
            Route::post('/tickets/{ticket}/ai/summary', [TicketAiController::class, 'summary'])->middleware('throttle:ai-staff');
            Route::post('/tickets/{ticket}/ai/translate', [TicketInsightsController::class, 'translate'])->middleware('throttle:ai-staff');
            Route::post('/tickets/{ticket}/ai/analyze', [TicketInsightsController::class, 'analyze'])->middleware('throttle:ai-staff');
            Route::post('/tickets/{ticket}/ai/triage/accept', [TicketInsightsController::class, 'acceptTriage']);
            Route::post('/tickets/{ticket}/ai/triage/dismiss', [TicketInsightsController::class, 'dismissTriage']);
            Route::get('/tickets/{ticket}/similar', [TicketInsightsController::class, 'similar']);
            Route::post('/tickets/{ticket}/watch', [TicketCollaborationController::class, 'watch']);
            Route::delete('/tickets/{ticket}/watch', [TicketCollaborationController::class, 'unwatch']);
            Route::get('/tickets/{ticket}/mentionable', [TicketCollaborationController::class, 'mentionable']);
            Route::post('/tickets/{ticket}/merge', [TicketCollaborationController::class, 'merge']);
            Route::put('/tickets/{ticket}/tags', [TagController::class, 'sync']);
            Route::get('/tags', [TagController::class, 'index']);

            Route::get('/canned-responses', [CannedResponseController::class, 'index']);
            Route::post('/canned-responses', [CannedResponseController::class, 'store']);
            Route::patch('/canned-responses/{cannedResponse}', [CannedResponseController::class, 'update']);
            Route::delete('/canned-responses/{cannedResponse}', [CannedResponseController::class, 'destroy']);
        });

        // Audit history (Lead & Admin; enforced by TicketPolicy)
        Route::get('/tickets/{ticket}/audits', [TicketController::class, 'audits']);

        // Lead & Admin Analytics
        Route::middleware('role:lead,admin')->group(function () {
            Route::get('/analytics/sla-overview', [SlaAnalyticsController::class, 'overview']);
            Route::get('/analytics/satisfaction', [SatisfactionController::class, 'index']);
            Route::get('/analytics/trends', [ReportController::class, 'trends']);
            Route::get('/tickets-export', [ReportController::class, 'exportTickets']);
            Route::get('/analytics/agent-workload', [SlaAnalyticsController::class, 'agentWorkload']);
            Route::get('/sla-policies', [SlaAnalyticsController::class, 'policies']);
        });

        // Lead & Admin: team roster, routing controls, knowledge base editing
        Route::middleware('role:lead,admin')->group(function () {
            Route::get('/kb-manage/articles', [ArticleController::class, 'manage']);
            Route::get('/kb-manage/analytics', [ArticleController::class, 'analytics']);
            Route::post('/kb-manage/articles', [ArticleController::class, 'store']);
            Route::patch('/kb-manage/articles/{article}', [ArticleController::class, 'update']);
            Route::delete('/kb-manage/articles/{article}', [ArticleController::class, 'destroy']);
            Route::get('/users', [UserController::class, 'index']);
            Route::patch('/users/{user}/routing', [UserController::class, 'updateRouting']);
        });

        // Admin only
        Route::middleware('role:admin')->group(function () {
            Route::get('/organizations', [OrganizationController::class, 'index']);
            Route::post('/organizations', [OrganizationController::class, 'store']);
            Route::patch('/organizations/{organization}', [OrganizationController::class, 'update']);
            Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy']);
            Route::post('/organizations/{organization}/attach-customers', [OrganizationController::class, 'attachCustomers']);
            Route::get('/organizations/{organization}/customers', [OrganizationController::class, 'customers']);
            Route::patch('/organizations/{organization}/customers/{user}', [OrganizationController::class, 'setCompanyAdmin']);
            Route::get('/webhooks', [WebhookController::class, 'index']);
            Route::post('/webhooks', [WebhookController::class, 'store']);
            Route::patch('/webhooks/{webhook}', [WebhookController::class, 'update']);
            Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy']);
            Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1');
            Route::get('/automation-rules', [AutomationRuleController::class, 'index']);
            Route::post('/automation-rules', [AutomationRuleController::class, 'store']);
            Route::patch('/automation-rules/{rule}', [AutomationRuleController::class, 'update']);
            Route::post('/automation-rules/{rule}/toggle', [AutomationRuleController::class, 'toggle']);
            Route::delete('/automation-rules/{rule}', [AutomationRuleController::class, 'destroy']);
            Route::get('/automation-rules/{rule}/runs', [AutomationRuleController::class, 'runs']);
            Route::get('/audits', [ReportController::class, 'audits']);
            Route::get('/ai/settings', [AiSettingsController::class, 'show']);
            Route::patch('/ai/settings', [AiSettingsController::class, 'update']);
            Route::patch('/users/{user}', [UserController::class, 'update']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);
            Route::post('/users/{user}/resend-invite', [UserController::class, 'resendInvite']);
            Route::post('/users/{user}/erase', [UserController::class, 'erase']);
            Route::post('/departments', [DepartmentController::class, 'store']);
            Route::patch('/departments/{department}', [DepartmentController::class, 'update']);
            Route::get('/holidays', [HolidayController::class, 'index']);
            Route::post('/holidays', [HolidayController::class, 'store']);
            Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);
            Route::post('/sla-policies', [SlaAnalyticsController::class, 'storePolicy']);
            Route::post('/users', [UserController::class, 'store']);
        });
    });
});
